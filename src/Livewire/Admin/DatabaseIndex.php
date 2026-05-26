<?php

namespace Nawasara\DatabaseMonitor\Livewire\Admin;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Nawasara\AuthPrimitives\Attributes\RequiresSudo;
use Nawasara\AuthPrimitives\Traits\WithSudo;
use Nawasara\DatabaseMonitor\Models\DbDatabase;
use Nawasara\DatabaseMonitor\Models\DbServer;
use Nawasara\DatabaseMonitor\Services\MysqlAdmin;

/**
 * Fase F admin page — list user databases and offer CREATE / DROP actions.
 *
 * The destructive action (DROP) is gated three ways:
 *   1. Config flag `nawasara-database-monitor.admin.enabled` must be true
 *      (env DB_MONITOR_ADMIN_ENABLED=true).
 *   2. Spatie permission `database-monitor.database.drop` on the route.
 *   3. #[RequiresSudo] on the action — operator must have re-authenticated
 *      via Keycloak step-up within the configured sudo window.
 *
 * The UI itself also asks for a type-the-name confirmation in the modal
 * before dispatching, so even with valid sudo a stray click can't drop.
 */
class DatabaseIndex extends Component
{
    use WithSudo;

    // -- Create form --
    public string $createName = '';

    public string $createCharset = 'utf8mb4';

    public string $createCollation = 'utf8mb4_unicode_ci';

    // -- Drop confirm --
    public ?string $dropTarget = null;

    public string $dropConfirmName = '';

    public function mount(): void
    {
        if (! app(MysqlAdmin::class)->isEnabled()) {
            abort(404);
        }
    }

    #[Computed]
    public function adminConfigured(): bool
    {
        return app(MysqlAdmin::class)->isConfigured();
    }

    #[Computed]
    public function server(): ?DbServer
    {
        return DbServer::where('slug', DbServer::SLUG_DEFAULT)->first();
    }

    #[Computed]
    public function databases()
    {
        $server = $this->server;
        if (! $server) {
            return collect();
        }

        // Only user databases — admin actions on `mysql`, `sys`, etc. are
        // never useful and only invite mistakes.
        return $server->databases()
            ->where('kind', DbDatabase::KIND_USER)
            ->orderBy('name')
            ->get();
    }

    #[RequiresSudo(reason: 'membuat database baru')]
    public function createDatabase(): void
    {
        $this->authorize('database-monitor.database.create');

        $this->validate([
            'createName' => 'required|string|min:1|max:64|regex:/^[A-Za-z0-9_]+$/',
            'createCharset' => 'required|string|max:32',
            'createCollation' => 'required|string|max:64',
        ], [
            'createName.regex' => 'Nama hanya boleh huruf, angka, underscore.',
        ]);

        $admin = app(MysqlAdmin::class);

        if (! $admin->isConfigured()) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Admin credential belum di-set di Vault.']);
            return;
        }

        try {
            $admin->createDatabase($this->createName, $this->createCharset, $this->createCollation);
            activity('database-monitor')
                ->event('database.create')
                ->withProperties(['name' => $this->createName, 'charset' => $this->createCharset])
                ->log("Database '{$this->createName}' created by ".auth()->user()?->email);

            $this->dispatch('toast', ['type' => 'success', 'message' => "Database '{$this->createName}' berhasil dibuat."]);
            $this->reset(['createName']);
            $this->dispatch('modal-close:db-monitor-create');
        } catch (\Throwable $e) {
            // PDO exceptions on DDL sometimes echo the failing SQL — never
            // surface that to the operator's toast/log channel because it
            // may carry sensitive context. report() captures the full
            // exception for Sentry/Laravel log; toast gets a generic
            // message; activity log records the failed attempt so the
            // audit trail captures BOTH successful and unsuccessful writes.
            report($e);
            activity('database-monitor')
                ->event('database.create_failed')
                ->withProperties(['name' => $this->createName])
                ->log("Database '{$this->createName}' create FAILED by ".auth()->user()?->email);
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Gagal membuat database (cek log aplikasi).']);
        } finally {
            $admin->purge();
        }
    }

    public function openDrop(string $name): void
    {
        $this->dropTarget = $name;
        $this->dropConfirmName = '';
        $this->dispatch('modal-open:db-monitor-drop');
    }

    public function closeDrop(): void
    {
        $this->dispatch('modal-close:db-monitor-drop');
        $this->dropTarget = null;
        $this->dropConfirmName = '';
    }

    /**
     * DROP is the most destructive action in this package — irreversible
     * data loss. The type-to-confirm UI defends against fat-finger, the
     * #[RequiresSudo] defends against compromised session, and the audit
     * log records the operator and exact target.
     */
    #[RequiresSudo(reason: 'menghapus database')]
    public function confirmDrop(): void
    {
        $this->authorize('database-monitor.database.drop');

        if (! $this->dropTarget) {
            return;
        }

        if ($this->dropConfirmName !== $this->dropTarget) {
            $this->addError('dropConfirmName', 'Ketik ulang nama database sama persis untuk konfirmasi.');
            return;
        }

        $admin = app(MysqlAdmin::class);

        if (! $admin->isConfigured()) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Admin credential belum di-set di Vault.']);
            return;
        }

        $target = $this->dropTarget;

        try {
            $admin->dropDatabase($target);
            activity('database-monitor')
                ->event('database.drop')
                ->withProperties(['name' => $target])
                ->log("Database '{$target}' DROPPED by ".auth()->user()?->email);

            // Remove from cache so the UI reflects reality without waiting
            // for the next inventory sync tick.
            DbDatabase::where('server_id', $this->server?->id)->where('name', $target)->delete();

            $this->dispatch('toast', ['type' => 'success', 'message' => "Database '{$target}' berhasil dihapus."]);
            $this->closeDrop();
        } catch (\Throwable $e) {
            report($e);
            activity('database-monitor')
                ->event('database.drop_failed')
                ->withProperties(['name' => $target])
                ->log("Database '{$target}' drop FAILED by ".auth()->user()?->email);
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Gagal menghapus database (cek log aplikasi).']);
        } finally {
            $admin->purge();
        }
    }

    public function render()
    {
        return view('nawasara-database-monitor::livewire.pages.admin.database-index')
            ->layout('nawasara-ui::components.layouts.app');
    }
}
