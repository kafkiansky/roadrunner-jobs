<?php

/**
 * This file is part of RoadRunner package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Spiral\RoadRunner\Jobs\Tests;

use Spiral\RoadRunner\Jobs\DTO\V1\DeclareRequest;
use Spiral\RoadRunner\Jobs\DTO\V1\Pipelines;
use Spiral\RoadRunner\Jobs\Exception\JobsException;
use Spiral\RoadRunner\Jobs\Jobs;
use Spiral\RoadRunner\Jobs\JobsInterface;
use Spiral\RoadRunner\Jobs\Queue\CreateInfo;
use Spiral\RoadRunner\Jobs\QueueInterface;

class JobsTestCase extends TestCase
{
    /**
     * @param array<string, string|callable> $mapping
     * @return JobsInterface
     */
    protected function jobs(array $mapping = []): JobsInterface
    {
        return new Jobs($this->rpc($mapping));
    }

    /**
     * @testdox Checking creating a new queue with given info.
     */
    public function testCreate(): void
    {
        $dto = new CreateInfo('bar', 'foo', CreateInfo::PRIORITY_DEFAULT_VALUE);

        $jobs = $this->jobs([
            'jobs.Declare' => function (DeclareRequest $request) use($dto) {
                $this->assertSame($dto->getName(), $request->getPipeline()->offsetGet('name'));
                $this->assertSame($dto->getDriver(), $request->getPipeline()->offsetGet('driver'));
                $this->assertSame('10', $request->getPipeline()->offsetGet('priority'));
            },
        ]);

        $queue = $jobs->create($dto);

        $this->assertSame('foo', $queue->getName());
    }

    /**
     * @testdox The "jobs" should be available when checking the list of plugins.
     */
    public function testIsAvailable(): void
    {
        $jobs = $this->jobs(['informer.List' => '["jobs"]']);

        $this->assertTrue($jobs->isAvailable());
    }

    /**
     * @testdox If the list does not return "jobs", then "isAvailable()" should return false.
     */
    public function testNotAvailable(): void
    {
        $jobs = $this->jobs(['informer.List' => '[]']);

        $this->assertFalse($jobs->isAvailable());
    }

    /**
     * @testdox When checking jobs existence, incorrect server responses are processed correctly.
     */
    public function testNotAvailableOnNonArrayResponse(): void
    {
        $jobs = $this->jobs(['informer.List' => '42']);

        $this->assertFalse($jobs->isAvailable());
    }

    /**
     * @testdox In the case that the RR returned an error, this is processed correctly and the false is returned.
     */
    public function testNotAvailableOnErrorResponse(): void
    {
        $jobs = $this->jobs(['informer.List' => (static function () {
            throw new \Exception();
        })]);

        $this->assertFalse($jobs->isAvailable());
    }

    /**
     * @testdox Checking the interaction with the RPC by the method of obtaining a list of queues.
     */
    public function testQueueListValues(): void
    {
        $expected = ['expected-queue-1', 'expected-queue-2'];

        $jobs = $this->jobs(['jobs.List' => function() use ($expected) {
            return new Pipelines(['pipelines' => $expected]);
        }]);

        // Execute "$jobs->getIterator()"
        $this->assertSame($expected, \array_map(
            static fn(QueueInterface $queue) => $queue->getName(),
            \array_values(\iterator_to_array($jobs))
        ));
    }

    /**
     * @testdox In case RPC returns an unrecognized error while retrieving the queue list, it is processed correctly.
     */
    public function testQueueListError(): void
    {
        $this->expectException(JobsException::class);

        \iterator_to_array($this->jobs());
    }

    /**
     * @testdox Checking the interaction with the RPC by the method of obtaining a count of available queues.
     */
    public function testQueueListCount(): void
    {
        $expected = ['expected-queue-1', 'expected-queue-2'];

        $jobs = $this->jobs(['jobs.List' => function() use ($expected) {
            return new Pipelines(['pipelines' => $expected]);
        }]);

        $this->assertCount(2, $jobs);
    }

    /**
     * @testdox In case RPC returns an unrecognized error while retrieving the queues count, it is processed correctly.
     */
    public function testQueueListCountError(): void
    {
        $this->expectException(JobsException::class);

        \count($this->jobs());
    }

    /**
     * @testdox Checking that the continuation (resume) of queues is sending the correct request.
     */
    public function testQueuesResume(): void
    {
        $actual = [];

        $jobs = $this->jobs(['jobs.Resume' => function(Pipelines $req) use (&$actual) {
            foreach ($req->getPipelines() as $pipeline) $actual[] = $pipeline;
        }]);

        $jobs->resume(
            $jobs->connect('queue-1'),
            $jobs->connect('queue-2')
        );

        $this->assertSame(['queue-1', 'queue-2'], $actual);
    }

    /**
     * @testdox Checking the correctness of error handling when calling the command to resuming queues.
     */
    public function testQueuesResumeError(): void
    {
        $this->expectException(JobsException::class);

        $jobs = $this->jobs();

        $jobs->resume(
            $jobs->connect('queue-1'),
            $jobs->connect('queue-2')
        );
    }

    /**
     * @testdox Checking that stopping (pausing) the queues is sending the correct request.
     */
    public function testQueuesPause(): void
    {
        $actual = [];

        $jobs = $this->jobs(['jobs.Pause' => function(Pipelines $req) use (&$actual) {
            foreach ($req->getPipelines() as $pipeline) $actual[] = $pipeline;
        }]);

        $jobs->pause(
            $jobs->connect('queue-1'),
            $jobs->connect('queue-2')
        );

        $this->assertSame(['queue-1', 'queue-2'], $actual);
    }

    /**
     * @testdox Checking the correctness of error handling when calling the command to pausing queues.
     */
    public function testQueuesPauseError(): void
    {
        $this->expectException(JobsException::class);

        $jobs = $this->jobs();

        $jobs->pause(
            $jobs->connect('queue-1'),
            $jobs->connect('queue-2')
        );
    }


    public function testQueueConnection(): void
    {
        $jobs = $this->jobs();

        $actual = $jobs->connect(
            $expected = \bin2hex(\random_bytes(32))
        );

        $this->assertSame($expected, $actual->getName());
    }
}
