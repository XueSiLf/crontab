<?php


namespace EasySwoole\Crontab;


use Cron\CronExpression;
use EasySwoole\Component\Process\Socket\AbstractUnixProcess;
use EasySwoole\Component\Timer;
use Swoole\Coroutine\Socket;
use Swoole\Table;

class Scheduler extends AbstractUnixProcess
{
    /** @var Table */
    private $scheduleTable;

    /** @var Crontab */
    private $crontabInstance;

    private $timerIds = [];

    public function run($arg)
    {
        $this->crontabInstance = $arg['crontabInstance'];
        $this->scheduleTable =  $arg['scheduleTable'];
        //异常的时候，worker会退出。先清空一遍规则,禁止循环的时候删除key
        $keys = [];
        foreach ($this->scheduleTable as $key => $value) {
            $keys[] = $key;
        }
        foreach ($keys as $key) {
            $this->scheduleTable->del($key);
        }

        $jobs = $arg['jobs'];
        /**
         * @var  $jobName
         * @var JobInterface $job
         */
        foreach ($jobs as $jobName => $job) {
            $nextTime = CronExpression::factory($job->crontabRule())->getNextRunDate()->getTimestamp();
            $this->scheduleTable->set($jobName, ['taskRule' => $job->crontabRule(), 'taskRunTimes' => 0, 'taskNextRunTime' => $nextTime, 'taskCurrentRunTime' => 0, 'isStop' => 0]);
        }
        $this->cronProcess();
        //60无法被8整除。
        Timer::getInstance()->loop(8 * 1000, function () {
            $this->cronProcess();
        });

        parent::run($arg);
    }

    function onAccept(Socket $socket)
    {

    }


    private function cronProcess()
    {
        foreach ($this->scheduleTable as $jobName => $task) {
            if (intval($task['isStop']) == 1) {
                continue;
            }
            $nextRunTime = CronExpression::factory($task['taskRule'])->getNextRunDate()->getTimestamp();
            if ($task['taskNextRunTime'] != $nextRunTime) {
                $this->scheduleTable->set($jobName, ['taskNextRunTime' => $nextRunTime]);
            }
            //本轮已经创建过任务
            if (isset($this->timerIds[$jobName])) {
                continue;
            }
            $distanceTime = $nextRunTime - time();
            $timerId = Timer::getInstance()->after($distanceTime * 1000, function () use ($jobName) {
                unset($this->timerIds[$jobName]);
                try{
                    $this->crontabInstance->rightNow($jobName);
                }catch (\Throwable $throwable){
                    $call = $this->crontabInstance->getConfig()->getOnException();
                    if(is_callable($call)){
                        call_user_func($call,$throwable);
                    }else{
                        throw $throwable;
                    }
                }
            });
            if ($timerId) {
                $this->timerIds[$jobName] = $timerId;
            }
        }
    }
}