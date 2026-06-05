<?php

namespace App\Console\Commands;

use App\Services\RescueSweepService;
use App\Services\RescueSweepThreshold;
use Illuminate\Console\Command;

class NotificationRescueSweepCommand extends Command
{
    protected $signature = 'notifications:rescue-sweep
        {--threshold= : Порог потери в минутах (по умолчанию из конфига)}
        {--batch-size= : Размер батча выборки из БД (по умолчанию из конфига)}
        {--max-process= : Максимум обрабатываемых уведомлений за запуск (по умолчанию из конфига)}';

    protected $description = 'Находит потерявшиеся уведомления и возвращает их в retry-очередь';

    public function handle(RescueSweepService $service, RescueSweepThreshold $threshold): int
    {
        $thresholdMinutes = (int) ($this->option('threshold') ?? config('notifications.rescue_sweep.threshold_minutes'));
        $batchSize = (int) ($this->option('batch-size') ?? config('notifications.rescue_sweep.batch_size'));
        $maxProcess = (int) ($this->option('max-process') ?? config('notifications.rescue_sweep.max_process'));

        // Значение из конфига проверено при старте, а ручное переопределение проверяем здесь
        if (! $threshold->isValid($thresholdMinutes)) {
            $this->error(sprintf(
                'Порог (%d мин) должен превышать TTL самой долгой retry-очереди (%d мин), '
                .'иначе уведомления, ожидающие в retry-очереди, будут поставлены в очередь повторно.',
                $thresholdMinutes,
                $threshold->maxRetryTtlMinutes(),
            ));

            return Command::INVALID;
        }

        $result = $service->sweep($thresholdMinutes, $batchSize, $maxProcess);

        $this->line(sprintf(
            'Rescue sweep завершён: найдено=%d, возвращено=%d, dropped=%d, ошибок=%d, пропущено=%d',
            $result->found,
            $result->requeued,
            $result->dropped,
            $result->failed,
            $result->skipped,
        ));

        return Command::SUCCESS;
    }
}
