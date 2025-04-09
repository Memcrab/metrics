<?php declare(strict_types=1);

namespace Memcrab\Metrics;

use OpenSwoole\Coroutine;

class CoroutineContextProcessor
{
    public function __invoke(PointWithContext $PointWithContext): PointWithContext
    {
        # Coroutine::getCid() > 0 - The code is running inside a coroutine,
        # Coroutine::getCid() == -1 - The code is running in the main thread (outside of a coroutine)
        $isRunningInCoroutine = Coroutine::getCid() > 0;
        $isCoroutineHookEnable = (bool) Coroutine::getOptions();

        $PointWithContext->context['isRunningInCoroutine'] = $isRunningInCoroutine;
        $PointWithContext->context['isCoroutineHookEnable'] = $isCoroutineHookEnable;

        return $PointWithContext;
    }
}