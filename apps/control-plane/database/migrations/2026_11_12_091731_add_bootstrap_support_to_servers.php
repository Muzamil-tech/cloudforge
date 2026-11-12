<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade databases created before the bootstrap feature was introduced.
     *
     * Fresh installations receive these columns from the create migration, so
     * every operation here is guarded by a schema check.
     */
    public function up(): void
    {
        if (Schema::hasTable('servers')) {
            if (Schema::hasColumn('servers', 'organization_id')) {
                Schema::table('servers', function (Blueprint $table): void {
                    $table->uuid('organization_id')->nullable()->change();
                });
            }

            if (! Schema::hasColumn('servers', 'machine_id')) {
                Schema::table('servers', function (Blueprint $table): void {
                    $table->string('machine_id')->nullable()->unique()->after('organization_id');
                });
            }

            if (! Schema::hasColumn('servers', 'is_bootstrap')) {
                Schema::table('servers', function (Blueprint $table): void {
                    $table->boolean('is_bootstrap')->default(false)->after('machine_id');
                });
            }

            if (! Schema::hasColumn('servers', 'claimed_at')) {
                Schema::table('servers', function (Blueprint $table): void {
                    $table->timestamp('claimed_at')->nullable()->after('is_bootstrap');
                });
            }
        }

        if (Schema::hasTable('server_enrollments')) {
            if (Schema::hasColumn('server_enrollments', 'organization_id')) {
                Schema::table('server_enrollments', function (Blueprint $table): void {
                    $table->uuid('organization_id')->nullable()->change();
                });
            }

            if (! Schema::hasColumn('server_enrollments', 'purpose')) {
                Schema::table('server_enrollments', function (Blueprint $table): void {
                    $table->string('purpose')->default('installation')->after('token_hash');
                });
            }

            $this->removeEnrollmentDefaults();
        }
    }

    /**
     * Reverse the migrations.
     *
     * Organization nullability is intentionally not restored: a database that
     * already contains an unclaimed bootstrap server cannot satisfy the old
     * non-null constraint without deleting real data.
     */
    public function down(): void
    {
        if (Schema::hasTable('server_enrollments') && Schema::hasColumn('server_enrollments', 'purpose')) {
            Schema::table('server_enrollments', function (Blueprint $table): void {
                $table->dropColumn('purpose');
            });
        }

        foreach (['claimed_at', 'is_bootstrap', 'machine_id'] as $column) {
            if (Schema::hasTable('servers') && Schema::hasColumn('servers', $column)) {
                Schema::table('servers', function (Blueprint $table) use ($column): void {
                    if ($column === 'machine_id') {
                        $table->dropUnique(['machine_id']);
                    }

                    $table->dropColumn($column);
                });
            }
        }
    }

    private function removeEnrollmentDefaults(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('alter table server_enrollments alter column expires_at drop default');
            DB::statement('alter table server_enrollments alter column consumed_at drop default');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // DB::statement('alter table server_enrollments alter column expires_at drop default');
            // DB::statement('alter table server_enrollments alter column consumed_at drop default');

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('server_enrollments', function (Blueprint $table): void {
                $table->timestamp('expires_at')->nullable(false)->default(null)->change();
                $table->timestamp('consumed_at')->nullable()->default(null)->change();
            });
        }
    }
};
