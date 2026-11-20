<?php

declare(strict_types=1);

namespace Faultline\Command;

use Faultline\Agent\DuelRunner;
use Faultline\Chaos\ChaosScenarioCatalog;
use Faultline\Ledger\LedgerStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'faultline:duel',
    description: 'Run deterministic MCP Chaos Duels comparing stock Symfony AI Agent vs Faultline-Guarded Agent.',
)]
final class RunChaosDuelCommand extends Command
{
    public function __construct(
        private readonly ?string $defaultDbPath = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scenario', 's', InputOption::VALUE_REQUIRED, 'Scenario ID or "all"', 'all')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Deterministic PRNG seed', '20261123')
            ->addOption('live-hf', null, InputOption::VALUE_NONE, 'Use live Hugging Face Inference Provider if HF_TOKEN is set')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON receipt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $scenarioOpt = (string) $input->getOption('scenario');
        $seed = (int) $input->getOption('seed');
        $liveHf = (bool) $input->getOption('live-hf');
        $asJson = (bool) $input->getOption('json');

        $dbPath = $this->defaultDbPath ?? (\dirname(__DIR__, 2).'/var/faultline.sqlite');
        $ledger = new LedgerStore($dbPath);
        $runner = new DuelRunner($ledger);

        $scenarios = 'all' === $scenarioOpt
            ? array_keys(ChaosScenarioCatalog::all())
            : [$scenarioOpt];

        $results = [];
        foreach ($scenarios as $id) {
            $results[] = $runner->runDuel($id, $seed, $liveHf);
        }

        if ($asJson) {
            $output->writeln((string) json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->title(\sprintf('FAULTLINE MCP · DETERMINISTIC CHAOS FLIGHT RECORDER (SEED #%d)', $seed));

        $rows = [];
        foreach ($results as $res) {
            $scen = $res['scenario'];
            $uEval = $res['unguarded']['evaluation'];
            $gEval = $res['guarded']['evaluation'];

            $rows[] = [
                $scen['id'],
                \sprintf('%s (%+.2f EUR)', $uEval['verdict'], $uEval['actual_delta_cents'] / 100),
                \sprintf('%d/100 (%d tools, %d writes)', $res['unguarded']['resilience_score'], $res['unguarded']['exposed_tool_count'], $uEval['write_count']),
                \sprintf('%s (%+.2f EUR)', $gEval['verdict'], $gEval['actual_delta_cents'] / 100),
                \sprintf('%d/100 (%d tools, %d writes)', $res['guarded']['resilience_score'], $res['guarded']['exposed_tool_count'], $gEval['write_count']),
                \sprintf('EUR %s', $res['comparison']['capital_protected_eur']),
            ];
        }

        $table = new Table($output);
        $table->setHeaders([
            'Scenario',
            'Unguarded Verdict (Delta)',
            'Unguarded Score',
            'Faultline Guarded (Delta)',
            'Guarded Score',
            'Capital Saved',
        ]);
        $table->setRows($rows);
        $table->render();

        $io->newLine();
        $io->success('All deterministic chaos duels completed with reproducible SQLite wire traces.');

        return Command::SUCCESS;
    }
}
