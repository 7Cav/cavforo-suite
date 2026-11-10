<?php

namespace Cav7\Citations\Setup;

use Cav7\Citations\Storage\BlobStore;
use Cav7\Citations\Storage\Reencoder;
use Cav7\Citations\Storage\RowKind;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\Db\SchemaManager;

/**
 * Every step tolerates what it finds, because uninstall keeps these tables and columns
 * and a reinstall has to pick them back up.
 */
final class StorageSchema
{
    public static function install(SchemaManager $schema): void
    {
        $schema->createTable(BlobStore::TABLE, function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('blob_id', 'int')->autoIncrement();
            $table->addColumn('content_hash', 'varbinary', 32);
            $table->addColumn('url_token', 'char', 32);
            $table->addColumn('format', 'enum')->values(['jpeg', 'png', 'gif']);
            $table->addColumn('origin', 'enum')->values(['source', 'encoded']);
            $table->addColumn('byte_size', 'int');
            $table->addColumn('width', 'int')->setDefault(0);
            $table->addColumn('height', 'int')->setDefault(0);
            $table->addColumn('ref_count', 'int')->setDefault(0);
            $table->addColumn('created_date', 'int');
            $table->addColumn('keep_reason', 'enum')
                ->values(['already_jpeg', 'grew', 'decode_failed', 'gif', 'reverted'])
                ->nullable()
                ->setDefault(null);
            $table->addColumn('encoder_label', 'varchar', 50)->setDefault('');
            $table->addColumn('imagemagick_version', 'varchar', 50)->setDefault('');
            $table->addColumn('libjpeg_version', 'varchar', 50)->setDefault('');
            $table->addUniqueKey('content_hash');
            $table->addKey('ref_count');
            $table->addKey(['origin', 'keep_reason', 'created_date'], 'reencode_pick');
        });

        foreach (RowKind::cases() as $kind) {
            if ($schema->columnExists($kind->table(), 'citation_blob_id')) {
                continue;
            }

            $schema->alterTable($kind->table(), function (Alter $table) {
                $table->addColumn('citation_blob_id', 'int')->nullable()->setDefault(null)->after('citation_date');
                $table->addKey('citation_blob_id');
            });
        }

        $schema->createTable(Reencoder::RUN_TABLE, function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('run_id', 'int')->autoIncrement();
            $table->addColumn('started_date', 'int');
            $table->addColumn('finished_date', 'int')->setDefault(0);
            $table->addColumn('archive_sync_date', 'int');
            $table->addColumn('encoder_label', 'varchar', 50);
            $table->addColumn('encoded_count', 'int')->setDefault(0);
            $table->addColumn('kept_count', 'int')->setDefault(0);
            $table->addColumn('damage_count', 'int')->setDefault(0);
            $table->addColumn('rows_moved', 'int')->setDefault(0);
        });

        $schema->createTable(Reencoder::LOG_TABLE, function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('log_id', 'int')->autoIncrement();
            $table->addColumn('run_id', 'int');
            $table->addColumn('row_kind', 'enum')->values(['award', 'record']);
            $table->addColumn('record_id', 'int');
            $table->addColumn('source_blob_id', 'int');
            $table->addColumn('source_hash', 'varbinary', 32);
            $table->addColumn('source_archive_key', 'varchar', 100);
            $table->addColumn('encoded_blob_id', 'int');
            $table->addColumn('reverted_date', 'int')->setDefault(0);
            $table->addColumn('reverted_to_blob_id', 'int')->nullable()->setDefault(null);
            $table->addKey('run_id');
            $table->addKey('encoded_blob_id');
            $table->addKey('source_blob_id');
            $table->addKey(['row_kind', 'record_id'], 'row');
        });

        $schema->createTable(BlobStore::DELETION_LOG, function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('log_id', 'int')->autoIncrement();
            $table->addColumn('deleted_date', 'int');
            $table->addColumn('reason', 'enum')
                ->values(['orphan_rowless', 'orphan_grant_linked', 'zero_reference', 'replaced_source']);
            $table->addColumn('path', 'varchar', 100);
            $table->addColumn('byte_size', 'int');
            $table->addColumn('content_hash', 'varbinary', 32);
            $table->addColumn('blob_id', 'int')->nullable()->setDefault(null);
            $table->addColumn('last_ref_count', 'int')->nullable()->setDefault(null);
            $table->addColumn('brake_lifted', 'tinyint')->setDefault(0);
            $table->addKey('deleted_date');
            $table->addKey('content_hash');
        });
    }
}
