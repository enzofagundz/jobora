<?php

namespace App\Console\Commands;

use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Services\ConceptNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('job-posting-items:normalize {--dry-run : Simula sem persistir alterações}')]
#[Description('Normaliza e deduplica JobPostingItems de vagas confirmadas, com relatório')]
class NormalizeJobPostingItems extends Command
{
    private int $analyzed = 0;

    private int $groups = 0;

    private int $normalized = 0;

    private int $removed = 0;

    private int $orphansFound = 0;

    private int $orphansRemoved = 0;

    private int $skippedAmbiguous = 0;

    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(private readonly ConceptNormalizer $normalizer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->analyzed = JobPostingItem::query()
            ->whereHas('jobPosting', fn ($query) => $query->where('status', JobPostingStatus::Confirmed))
            ->count();

        $this->handleOrphans();

        JobPosting::query()
            ->where('status', JobPostingStatus::Confirmed)
            ->select('id')
            ->orderBy('id')
            ->chunkById(50, function (Collection $postings): void {
                foreach ($postings as $posting) {
                    $this->processPosting((int) $posting->id);
                }
            });

        $this->renderReport();

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function processPosting(int $postingId): void
    {
        try {
            DB::transaction(function () use ($postingId): void {
                $posting = JobPosting::query()->whereKey($postingId)->lockForUpdate()->firstOrFail();

                $groups = $posting->items()->orderBy('id')->get()
                    ->groupBy(fn (JobPostingItem $item): string => $item->category->value.'|'.$this->normalizer->clean($item->name))
                    ->filter(fn (Collection $group): bool => $group->count() > 1);

                foreach ($groups as $group) {
                    $this->deduplicateGroup($postingId, $group);
                }
            }, 5);
        } catch (Throwable $e) {
            $this->errors[] = "JobPosting #{$postingId}: {$e->getMessage()}";
        }
    }

    /**
     * @param  Collection<int, JobPostingItem>  $group
     */
    private function deduplicateGroup(int $postingId, Collection $group): void
    {
        if ($this->normalizer->clean($group->first()->name) === '') {
            $this->skippedAmbiguous++;
            $this->components->warn("Ignorado grupo ambíguo em JobPosting #{$postingId} (nome em branco).");

            return;
        }

        $this->groups++;

        // ponytail: canonical = user → menor created_at → menor id; determinístico.
        $canonical = $group->sort(function (JobPostingItem $a, JobPostingItem $b): int {
            return [
                $a->source === ItemSource::User ? 0 : 1,
                (string) $a->created_at,
                $a->id,
            ] <=> [
                $b->source === ItemSource::User ? 0 : 1,
                (string) $b->created_at,
                $b->id,
            ];
        })->first();

        $duplicates = $group->where('id', '!==', $canonical->id)->values();

        $this->renderGroup($postingId, $canonical, $duplicates);

        if ($this->option('dry-run')) {
            return;
        }

        $this->normalized += $duplicates->filter(fn (JobPostingItem $item): bool => $item->name !== $canonical->name)->count();
        $this->removed += $duplicates->count();

        JobPostingItem::query()->whereIn('id', $duplicates->pluck('id'))->delete();
    }

    private function renderGroup(int $postingId, JobPostingItem $canonical, Collection $duplicates): void
    {
        $this->newLine();
        $this->components->info('Grupo:');
        $this->line("  JobPosting #{$postingId}");
        $this->line('  Categoria: '.$canonical->category->value);
        $this->line('');
        $this->line('  Canonical:');
        $this->line('    #'.$canonical->id.' "'.$canonical->name.'"');
        $this->line('');
        $this->line('  Duplicates:');
        foreach ($duplicates as $duplicate) {
            $this->line('    #'.$duplicate->id.' "'.$duplicate->name.'"');
        }
        $this->line('');
        $this->line('  Ação:');
        foreach ($duplicates as $duplicate) {
            $this->line('    #'.$duplicate->id.' → #'.$canonical->id);
        }
    }

    private function handleOrphans(): void
    {
        $orphanIds = JobPostingItem::query()
            ->whereDoesntHave('jobPosting')
            ->orderBy('id')
            ->pluck('id');

        $this->orphansFound = $orphanIds->count();

        if ($this->orphansFound === 0) {
            return;
        }

        $this->components->warn(sprintf(
            'Encontrados %d itens órfãos (sem vaga correspondente): %s',
            $this->orphansFound,
            $orphanIds->implode(', '),
        ));

        if ($this->option('dry-run')) {
            return;
        }

        DB::transaction(function () use ($orphanIds): void {
            JobPostingItem::query()->whereIn('id', $orphanIds)->delete();
        });

        $this->orphansRemoved = $this->orphansFound;
    }

    private function renderReport(): void
    {
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->components->info('Nenhuma alteração realizada (dry-run).');
        }

        $this->components->info('Relatório:');
        $this->line('  Registros analisados: '.$this->analyzed);
        $this->line('  Grupos duplicados encontrados: '.$this->groups);
        $this->line('  Registros normalizados: '.$this->normalized);
        $this->line('  Registros removidos: '.$this->removed);
        $this->line('  Referências atualizadas: 0'); // ponytail: nenhuma tabela referencia job_posting_items.id
        $this->line('  Grupos ignorados por ambiguidade: '.$this->skippedAmbiguous);
        $this->line('  Itens órfãos encontrados: '.$this->orphansFound);
        $this->line('  Itens órfãos removidos: '.$this->orphansRemoved);
        $this->line('  Erros: '.count($this->errors));

        foreach ($this->errors as $error) {
            $this->components->error($error);
        }
    }
}
