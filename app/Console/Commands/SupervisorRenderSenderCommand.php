<?php

namespace App\Console\Commands;

use App\Messaging\DeliveryProfile;
use App\Messaging\DeliveryProfileConfig;
use Illuminate\Console\Command;

/**
 * Генерирует /etc/supervisor/conf.d/sender.conf из профилей доставки.
 * Вызывается entrypoint-скриптом контейнера worker перед стартом supervisord.
 */
class SupervisorRenderSenderCommand extends Command
{
    protected $signature = 'supervisor:render-sender
        {--output=/etc/supervisor/conf.d/sender.conf : Путь к генерируемому conf-файлу}';

    protected $description = 'Генерирует supervisord-секции sender-* из каталога приоритетов доставки.';

    public function handle(): int
    {
        // Разрешаем DeliveryProfile здесь, а не через конструктор, чтобы при
        // невалидном конфиге (пустом каталоге) команда вернула FAILURE вместо
        // необработанного исключения при разрешении DI.
        try {
            /** @var DeliveryProfile $profile */
            $profile = $this->laravel->make(DeliveryProfile::class);
        } catch (\Throwable $e) {
            $this->error('Не удалось получить каталог приоритетов: '.$e->getMessage());

            return self::FAILURE;
        }

        $all = $profile->all();

        if ($all === []) {
            $this->error('Каталог приоритетов пуст, файл не будет записан.');

            return self::FAILURE;
        }

        $sections = array_map(
            fn (DeliveryProfileConfig $config) => $this->buildSection($config),
            array_values($all),
        );

        $content = implode("\n\n", $sections)."\n";
        $outputPath = (string) $this->option('output');
        $dir = dirname($outputPath);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Не удалось создать директорию: {$dir}");

            return self::FAILURE;
        }

        if (file_put_contents($outputPath, $content) === false) {
            $this->error("Не удалось записать файл: {$outputPath}");

            return self::FAILURE;
        }

        $names = implode(', ', array_map(
            fn (DeliveryProfileConfig $c) => 'sender-'.$c->name,
            array_values($all),
        ));

        $this->info(sprintf('rendered %d program(s): %s', count($all), $names));

        return self::SUCCESS;
    }

    private function buildSection(DeliveryProfileConfig $config): string
    {
        return <<<CONF
[program:sender-{$config->name}]
command=php /app/artisan notifications:send --queue={$config->name} --prefetch={$config->prefetch}
user=www-data
directory=/app
autostart=true
autorestart=true
startsecs=5
startretries=10
stopsignal=TERM
stopwaitsecs=35
numprocs={$config->workers}
process_name=%(program_name)s_%(process_num)02d
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
environment=APP_ENV="%(ENV_APP_ENV)s"
CONF;
    }
}
