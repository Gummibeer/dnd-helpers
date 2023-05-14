<?php

namespace App\Commands;

use DiceBag\DiceBag;
use Illuminate\Config\Repository as Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Matex\Evaluator;
use function Termwind\{render};

class ProduceMaterialsCommand extends Command
{
    protected $signature = 'material:produce {location} {--days=1}';

    protected $description = 'Run material procution calculation for the given location.';

    public function handle(): int
    {
        $locations = collect(parse_ini_file(
            filename: base_path('locations.conf'),
            process_sections: true,
            scanner_mode: INI_SCANNER_TYPED
        ))->dot()->undot();

        if (! $locations->has($this->argument('location'))) {
            render(<<<HTML
            <div class="ml-2">
                <div class="px-1 bg-red-300 text-black font-bold">Error</div>
                <span class="ml-1">Use one of the configured locations:</span>
                <ul class="italic">
                    <li>{$locations->keys()->implode('</li><li>')}</li>
                </ul>
            </div>
            HTML);

            return self::FAILURE;
        }

        render(<<<HTML
        <div class="ml-2">
            <div class="px-1 bg-blue-300 text-black font-bold">{$this->argument('location')}</div>
        </div>
        HTML);

        collect($locations->get($this->argument('location')))->each(function (array $config, string $name): void {
            $config = new Config($config);

            $result = Collection::times($this->option('days'), function () use ($config): Collection {
                return Collection::times($this->calculateTimes($config), function () use ($config): Collection {
                    $random = DiceBag::factory('d20')->getTotal();

                    return collect($config['production'])
                        ->map(function (array $material) use ($random) {
                            $amount = DiceBag::factory($material['fixed'] ?? '0')->getTotal();

                            if($material['min'] <= $random && $random <= $material['max']) {
                                $amount += DiceBag::factory($material['rate'] ?? '0')->getTotal();
                            }

                            return $amount;
                        });
                });
            })->collapse();

            $production = collect(array_flip(array_keys($config['production'])))
                ->map(fn ($_, string $material) => $result->sum($material));

            $tbody = $production
                ->keyBy(fn(int $amount, string $material) => Str::headline($material))
                ->map(fn(int $amount, string $material) => <<<HTML
                <tr>
                    <td>{$material}</td>
                    <td>{$amount}</td>
                </tr>
                HTML)
                ->implode(PHP_EOL);

            render(<<<HTML
            <div class="ml-4">
                <div class="px-1 bg-yellow-300 text-black font-bold capitalize">{$name}</div>
                <span class="ml-2">{$this->option('days')} days</span>
                <table>
                <thead>
                <tr>
                    <th>Material</th>
                    <th>Amount</th>
                </tr>
                </thead>
                <tbody>{$tbody}</tbody>
                </table>
            </div>
            HTML);
        });

        return self::SUCCESS;
    }

    protected function calculateTimes(Config $config): int
    {
        $force = $config['workers'] + min($config['tools'] ?? 0, $config['workers']);
        $times = $force * (new Evaluator())->execute($config['rate']);

        $times += Lottery::odds($times - floor($times))
            ->winner(fn () => 1)
            ->loser(fn () => 0)
            ->choose();

        if (DiceBag::factory('d20')->getTotal() === 20) {
            $times += 1;
        }

        return (int) floor($times);
    }
}
