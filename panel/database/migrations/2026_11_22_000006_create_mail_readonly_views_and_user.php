<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const VIEWS = [
        'v_postfix_domains',
        'v_postfix_mailboxes',
        'v_postfix_aliases',
        'v_postfix_mailbox_self',
        'v_postfix_sender_mailboxes',
        'v_postfix_sender_aliases',
        'v_dovecot_users',
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        $isMysqlFamily = in_array($driver, ['mysql', 'mariadb'], true);
        $viewPrefix = $isMysqlFamily
            ? 'CREATE OR REPLACE SQL SECURITY INVOKER VIEW'
            : 'CREATE VIEW';

        if (! $isMysqlFamily) {
            foreach (self::VIEWS as $view) {
                DB::statement("DROP VIEW IF EXISTS {$view}");
            }
        }

        $maildirExpr = $isMysqlFamily
            ? "CONCAT(d.domain, '/', a.local_part, '/')"
            : "d.domain || '/' || a.local_part || '/'";

        DB::statement(<<<SQL
            {$viewPrefix} v_postfix_domains AS
            SELECT domain
            FROM mail_domains
            WHERE active = 1
        SQL);

        DB::statement(<<<SQL
            {$viewPrefix} v_postfix_mailboxes AS
            SELECT a.email, {$maildirExpr} AS maildir
            FROM mail_accounts a
            INNER JOIN mail_domains d ON d.id = a.mail_domain_id
            WHERE a.active = 1 AND d.active = 1
        SQL);

        DB::statement(<<<SQL
            {$viewPrefix} v_postfix_aliases AS
            SELECT al.source_email, al.destination_email, al.is_catch_all
            FROM mail_aliases al
            INNER JOIN mail_domains d ON d.id = al.mail_domain_id
            WHERE al.active = 1 AND d.active = 1
        SQL);

        DB::statement(<<<SQL
            {$viewPrefix} v_postfix_mailbox_self AS
            SELECT a.email AS source_email, a.email AS destination_email
            FROM mail_accounts a
            INNER JOIN mail_domains d ON d.id = a.mail_domain_id
            WHERE a.active = 1 AND d.active = 1
        SQL);

        DB::statement(<<<SQL
            {$viewPrefix} v_postfix_sender_mailboxes AS
            SELECT a.email AS sender_email, a.email AS sasl_login
            FROM mail_accounts a
            INNER JOIN mail_domains d ON d.id = a.mail_domain_id
            WHERE a.active = 1 AND a.allow_smtp = 1 AND d.active = 1
        SQL);

        DB::statement(<<<SQL
            {$viewPrefix} v_postfix_sender_aliases AS
            SELECT al.source_email AS sender_email, a.email AS sasl_login
            FROM mail_aliases al
            INNER JOIN mail_domains d ON d.id = al.mail_domain_id
            INNER JOIN mail_accounts a ON a.email = al.destination_email
            WHERE al.active = 1 AND d.active = 1 AND a.active = 1 AND a.allow_smtp = 1
        SQL);

        DB::statement(<<<SQL
            {$viewPrefix} v_dovecot_users AS
            SELECT a.email, a.local_part, d.domain, a.password_hash, a.quota_mb, a.allow_imap, a.allow_pop3, a.allow_smtp
            FROM mail_accounts a
            INNER JOIN mail_domains d ON d.id = a.mail_domain_id
            WHERE a.active = 1 AND d.active = 1
        SQL);
    }

    public function down(): void
    {
        foreach (array_reverse(self::VIEWS) as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }
};
