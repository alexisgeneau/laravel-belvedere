<?php

declare(strict_types=1);

namespace Belvedere\Enums;

enum ActionType: string
{
    case Retry = 'retry';
    case Forget = 'forget';
    case Flush = 'flush';
    case Delete = 'delete';
    case RunNow = 'run_now';
    case Release = 'release';
    case Purge = 'purge';
    case Pause = 'pause';
    case Resume = 'resume';
    case CancelBatch = 'cancel_batch';
    case RetryBatch = 'retry_batch';
    case ForgetWorker = 'forget_worker';
}
