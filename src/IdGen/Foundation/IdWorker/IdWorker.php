<?php

namespace Adachi\IdGen\Foundation\IdWorker;

use Adachi\IdGen\Foundation\IdValue\Element\RegionId;
use Adachi\IdGen\Foundation\IdValue\Element\ServerId;
use Adachi\IdGen\Foundation\IdValue\Element\Timestamp;
use Adachi\IdGen\Foundation\IdValue\IdValueConfig;
use Adachi\IdGen\Foundation\IdValue\IdValue;

/**
 * Class IdWorker
 *
 * @package Adachi\IdGen\Foundation\IdWorker
 */
class IdWorker
{
    /**
     * @var \Adachi\IdGen\Foundation\IdValue\IdValueConfig
     */
    protected $config;

    /**
     * @var \Adachi\IdGen\Foundation\IdValue\Element\RegionId
     */
    protected $regionId;

    /**
     * @var \Adachi\IdGen\Foundation\IdValue\Element\ServerId
     */
    protected $serverId;

    /**
     * (mutable)
     *
     * @var \Adachi\IdGen\Foundation\IdValue\Element\Timestamp
     */
    protected $lastTimestamp = null;

    /**
     * @var int
     * @fixme refactor
     */
    private $semaphoreId;

    /**
     * Key name of shared memory block
     * @fixme refactor
     */
    const SHM_KEY = 12345;

    /**
     * Key name of shared memory segment
     * @fixme refactor
     */
    const SHM_SEQUENCE = 54321;

    /**
     * @param IdValueConfig $config
     * @param RegionId $regionId
     * @param ServerId $serverId
     * @param int $semaphoreId
     */
    public function __construct(IdValueConfig $config, RegionId $regionId, ServerId $serverId, $semaphoreId = 45454)
    {
        $this->config = $config;
        $this->regionId = $regionId;
        $this->serverId = $serverId;
        $this->semaphoreId = $semaphoreId;
    }


    /**
     * @param IdValue $value
     * @return int
     * @throws \RuntimeException
     */
    public function write(IdValue $value)
    {
        if ($value->timestamp->value <= $this->config->maxTimestamp &&
            $value->regionId->value <= $this->config->maxRegionId &&
            $value->serverId->value <= $this->config->maxServerId &&
            $value->sequence <= $this->config->maxSequence)
        {
            return  $this->calculateValue($value->timestamp, $value->regionId, $value->serverId, $value->sequence);
        }
        else
        {
            throw new \RuntimeException("IdValue Specification is not satisfied");
        }
    }

    /**
     * @param $value
     * @return IdValue
     * @throws \RuntimeException
     */
    public function read($value)
    {
        $timestamp = new Timestamp(($value & $this->config->timestampMask) >> $this->config->timestampBitShift);
        $regionId = new RegionId(($value & $this->config->regionIdMask) >> $this->config->regionIdBitShift);
        $serverId = new ServerId(($value & $this->config->serverIdMask) >> $this->config->serverIdBitShift);
        $sequence = ($value & $this->config->sequenceMask);

        if ($timestamp->value <= $this->config->maxTimestamp &&
            $regionId->value <= $this->config->maxRegionId &&
            $serverId->value <= $this->config->maxServerId &&
            $sequence <= $this->config->maxSequence)
        {
            return new IdValue($timestamp, $regionId, $serverId, $sequence, $this->calculateValue($timestamp, $regionId, $serverId, $sequence));
        }
        else
        {
            throw new \RuntimeException("IdValue Specification is not satisfied");
        }
    }

    /**
     * @return IdValue
     */
    public function generate()
    {
        $timestamp = $this->generateTimestamp();

        // Acquire semaphore
        $semaphore = sem_get($this->semaphoreId);
        sem_acquire($semaphore);

        // Attach shared memory
        $memory = shm_attach(self::SHM_KEY);

        $sequence = 0;

        if ( ! is_null($this->lastTimestamp) && $timestamp->equals($this->lastTimestamp))
        {
            // Increment sequence
            $sequence = (shm_get_var($memory, self::SHM_SEQUENCE) + 1) & $this->config->sequenceMask;
            shm_put_var($memory, self::SHM_SEQUENCE, $sequence);

            if ($sequence === 0)
            {
                usleep(1000);
                $timestamp = $this->generateTimestamp();
            }
        }
        else
        {
            // Reset sequence if timestamp is different from last one.
            $sequence = 0;
            shm_put_var($memory, self::SHM_SEQUENCE, $sequence);
        }

        // Detach shared memory
        shm_detach($memory);

        // Release semaphore
        sem_release($semaphore);

        // Update lastTimestamp
        $this->lastTimestamp = $timestamp;

        return new IdValue($timestamp, $this->regionId, $this->serverId, $sequence, $this->calculateValue($timestamp, $this->regionId, $this->serverId, $sequence));
    }

    /**
     * @todo make this protected, but it'll be difficult to test. any ideas?
     * @return Timestamp
     */
    public function generateTimestamp()
    {
        $stamp = (int) round(microtime(true) * 1000);
        return new Timestamp($stamp - $this->config->epoch);
    }

    /**
     * @param Timestamp $timestamp
     * @param RegionId $regionId
     * @param ServerId $serverId
     * @param $sequence
     * @return int
     */
    protected function calculateValue(Timestamp $timestamp, RegionId $regionId, ServerId $serverId, $sequence)
    {
        return ($timestamp->value << $this->config->timestampBitShift) |
               ($regionId->value << $this->config->regionIdBitShift)   |
               ($serverId->value << $this->config->serverIdBitShift)   |
               ($sequence);
    }
}