<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TOKEN_TYPES = ['email_verify', 'mobile_otp', 'password_reset', 'account_unlock'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $this->rebuildVerificationTokensTable(true, 'TIMESTAMP');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $this->rebuildVerificationTokensTable(false, 'DATETIME');
    }

    private function rebuildVerificationTokensTable(bool $enforceTokenTypes, string $expiresAtType): void
    {
        $unexpectedTypes = DB::table('verification_tokens')
            ->whereNotIn('type', self::TOKEN_TYPES)
            ->distinct()
            ->pluck('type');

        if ($enforceTokenTypes && $unexpectedTypes->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot constrain verification token types; unsupported values exist: '.$unexpectedTypes->implode(', '),
            );
        }

        $replacementTable = 'verification_tokens_replacement';
        if (Schema::hasTable($replacementTable)) {
            throw new \RuntimeException("Cannot migrate verification tokens because {$replacementTable} already exists.");
        }

        $typeConstraint = $enforceTokenTypes
            ? "CONSTRAINT verification_tokens_type_check CHECK (type IN ('email_verify', 'mobile_otp', 'password_reset', 'account_unlock'))"
            : '';

        DB::statement(
            "CREATE TABLE {$replacementTable} (
                id varchar NOT NULL PRIMARY KEY,
                user_id varchar NOT NULL,
                token_hash varchar NOT NULL,
                type varchar NOT NULL {$typeConstraint},
                expires_at {$expiresAtType} NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE NO ACTION
            )",
        );
        DB::statement(
            "INSERT INTO {$replacementTable} (id, user_id, token_hash, type, expires_at)
             SELECT id, user_id, token_hash, type, expires_at FROM verification_tokens",
        );
        Schema::drop('verification_tokens');
        Schema::rename($replacementTable, 'verification_tokens');
        DB::statement(
            'CREATE UNIQUE INDEX verification_tokens_user_id_type_unique ON verification_tokens (user_id, type)',
        );
    }
};
