<?php

namespace Tests\Support;

/**
 * For the assistant:eval tests (Phase 7E-2b): a sandbox file and report names of the test's own, inside
 * storage/app/evals, removed again after the test.
 */
trait RunsEval
{
    protected string $evalPrefix;

    protected function setUpEval(): void
    {
        $this->evalPrefix = 'test-'.bin2hex(random_bytes(4));
        config(['assistant.eval.database' => storage_path("app/evals/{$this->evalPrefix}.sqlite")]);
        $this->beforeApplicationDestroyed(fn () => $this->cleanUpEval());
    }

    protected function evalReportName(string $name = 'run'): string
    {
        return "{$this->evalPrefix}-{$name}";
    }

    protected function evalFile(string $name, string $extension = 'md'): string
    {
        return storage_path("app/evals/{$this->evalPrefix}-{$name}.{$extension}");
    }

    private function cleanUpEval(): void
    {
        foreach (glob(storage_path("app/evals/{$this->evalPrefix}*")) ?: [] as $file) {
            @unlink($file);
        }
    }
}
