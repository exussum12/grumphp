<?php

namespace GrumPHP\Runner;

use GrumPHP\Collection\TaskResultCollection;
use GrumPHP\Collection\TasksCollection;
use GrumPHP\Configuration\GrumPHP;
use GrumPHP\Event\RunnerEvent;
use GrumPHP\Event\RunnerEvents;
use GrumPHP\Event\RunnerFailedEvent;
use GrumPHP\Event\TaskEvent;
use GrumPHP\Event\TaskEvents;
use GrumPHP\Event\TaskFailedEvent;
use GrumPHP\Exception\RuntimeException;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\TaskInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class TaskRunner
 *
 * @package GrumPHP\Runner
 */
class TaskRunner
{
    /**
     * @var TasksCollection|TaskInterface[]
     */
    private $tasks;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var GrumPHP
     */
    private $grumPHP;

    /**
     * @constructor
     *
     * @param GrumPHP                  $grumPHP
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(GrumPHP $grumPHP, EventDispatcherInterface $eventDispatcher)
    {
        $this->tasks = new TasksCollection();
        $this->eventDispatcher = $eventDispatcher;
        $this->grumPHP = $grumPHP;
    }

    /**
     * @param TaskInterface $task
     */
    public function addTask(TaskInterface $task)
    {
        if ($this->tasks->contains($task)) {
            return;
        }

        $this->tasks->add($task);
    }

    /**
     * @return TasksCollection|TaskInterface[]
     */
    public function getTasks()
    {
        return $this->tasks;
    }

    /**
     * @param ContextInterface $context
     *
     * @return TaskResultCollection
     */
    public function run(ContextInterface $context)
    {
        $messages = array();
        $tasks = $this->tasks->filterByContext($context)->sortByPriority($this->grumPHP);
        $taskResuls = new TaskResultCollection();

        $this->eventDispatcher->dispatch(RunnerEvents::RUNNER_RUN, new RunnerEvent($tasks, $context));
        foreach ($tasks as $task) {
            try {
                $this->eventDispatcher->dispatch(TaskEvents::TASK_RUN, new TaskEvent($task, $context));
                $task->run($context);
                $taskResuls->add(new TaskResult(TaskResult::PASSED, $task, $context));
                $this->eventDispatcher->dispatch(TaskEvents::TASK_COMPLETE, new TaskEvent($task, $context));
            } catch (RuntimeException $e) {
                $taskResuls->add(
                    new TaskResult(
                        $this->isBlockingTask($task) ? TaskResult::FAILED : TaskResult::NONBLOCKING_FAILED,
                        $task,
                        $context,
                        $e->getMessage()
                    )
                );
                $this->eventDispatcher->dispatch(TaskEvents::TASK_FAILED, new TaskFailedEvent($task, $context, $e));
                $messages[] = $e->getMessage();

                if ($this->grumPHP->stopOnFailure()) {
                    break;
                }
            }
        }

        if (!$taskResuls->isPassed()) {
            $this->eventDispatcher->dispatch(
                RunnerEvents::RUNNER_FAILED,
                new RunnerFailedEvent($tasks, $context, $messages)
            );

            return $taskResuls;
        }

        $this->eventDispatcher->dispatch(RunnerEvents::RUNNER_COMPLETE, new RunnerEvent($tasks, $context));

        return $taskResuls;
    }

    /**
     * @param \GrumPHP\Task\TaskInterface $task
     * @return bool
     */
    private function isBlockingTask(TaskInterface $task)
    {
        $taskMetadata = $this->grumPHP->getTaskMetadata($task->getName());
        return $taskMetadata['blocking'];
    }
}
