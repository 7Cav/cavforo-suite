<?php

namespace Cav7\Citations\Setup;

use Cav7\Citations\Storage\RowKind;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\Db\SchemaManager;

/**
 * Tolerates what it finds, for the same reason as TemplateSchema. relation_id on a grant
 * member is deliberately no foreign key: the grant outlives a deleted milpac.
 */
final class GrantSchema
{
    public static function install(SchemaManager $schema): void
    {
        $schema->createTable('xf_cav7_citation_grant', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('grant_id', 'int')->autoIncrement();
            $table->addColumn('is_regiment', 'tinyint')->setDefault(0);
            $table->addColumn('kind_type', 'enum')->values(['award', 'record_type']);
            $table->addColumn('kind_id', 'int');
            $table->addColumn('kind_title', 'varchar', 150);
            $table->addColumn('template_version_id', 'int');
            $table->addColumn('grant_date', 'int');
            $table->addColumn('citation_text', 'text')->nullable();
            self::addRankColumns($table);
            $table->addColumn('input_values', 'mediumblob');
            $table->addColumn('revision', 'int')->setDefault(1);
            $table->addColumn('url_token', 'varchar', 32)->nullable();
            $table->addColumn('issue_user_id', 'int');
            $table->addColumn('issue_username', 'varchar', 50);
            $table->addColumn('issue_date', 'int');
            $table->addColumn('withdrawn_date', 'int')->setDefault(0);
            $table->addKey(['kind_type', 'kind_id', 'grant_date'], 'kind_date');
            $table->addKey('grant_date');
            $table->addKey('template_version_id');
            $table->addKey(['is_regiment', 'withdrawn_date'], 'regiment_live');
        });

        $schema->createTable('xf_cav7_citation_grant_member', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('grant_member_id', 'int')->autoIncrement();
            $table->addColumn('grant_id', 'int');
            $table->addColumn('relation_id', 'int');
            $table->addColumn('url_token', 'varchar', 32);
            $table->addColumn('name', 'varchar', 255)->setDefault('');
            self::addRankColumns($table);
            $table->addColumn('input_values', 'mediumblob');
            $table->addColumn('add_user_id', 'int');
            $table->addColumn('add_date', 'int');
            $table->addUniqueKey(['grant_id', 'relation_id'], 'grant_milpac');
            $table->addKey('relation_id');
        });

        $schema->createTable('xf_cav7_citation_grant_log', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('log_id', 'int')->autoIncrement();
            $table->addColumn('grant_id', 'int');
            $table->addColumn('event', 'enum')
                ->values(['corrected', 'member_added', 'member_removed', 'retypeset', 'withdrawn', 'puc_sync']);
            $table->addColumn('grant_member_id', 'int')->nullable();
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('log_date', 'int');
            $table->addColumn('reason', 'text');
            $table->addColumn('revision_after', 'int');
            $table->addColumn('detail', 'mediumblob');
            $table->addKey(['grant_id', 'log_date'], 'grant_date');
        });

        $schema->createTable('xf_cav7_citation_grant_signature', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('grant_id', 'int');
            $table->addColumn('field_key', 'varchar', 50);
            $table->addColumn('signature_id', 'int');
            $table->addPrimaryKey(['grant_id', 'field_key']);
            $table->addKey('signature_id');
        });

        foreach (RowKind::cases() as $kind) {
            if ($schema->columnExists($kind->table(), 'citation_grant_member_id')) {
                continue;
            }

            $schema->alterTable($kind->table(), function (Alter $table) {
                $table->addColumn('citation_grant_member_id', 'int')->nullable()->setDefault(null)->after('citation_blob_id');
                $table->addColumn('citation_regiment_grant_id', 'int')->nullable()->setDefault(null)->after('citation_grant_member_id');
                $table->addKey('citation_grant_member_id');
                $table->addKey('citation_regiment_grant_id');
            });
        }
    }

    private static function addRankColumns(Create $table): void
    {
        $table->addColumn('rank_id', 'int')->nullable();
        $table->addColumn('rank_title', 'varchar', 50)->nullable();
        $table->addColumn('rank_pay_grade', 'varchar', 10)->nullable();
        $table->addColumn('rank_abbreviation', 'varchar', 10)->nullable();
    }
}
