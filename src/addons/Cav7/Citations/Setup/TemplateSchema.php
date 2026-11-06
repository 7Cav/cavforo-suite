<?php

namespace Cav7\Citations\Setup;

use Cav7\Citations\Template\PayGradeSeed;
use Cav7\Citations\Template\TypesettingRows;
use XF\Db\AbstractAdapter;
use XF\Db\Schema\Create;
use XF\Db\SchemaManager;

/**
 * Every step tolerates what it finds, because uninstall keeps these tables and a reinstall
 * has to pick them back up. JSON columns are binary: the render core decodes them, and
 * XF's JSON type would hand it arrays. The seed assumes NF/Rosters keeps rank titles in
 * xf_nf_rosters_rank.
 */
final class TemplateSchema
{
    public static function install(SchemaManager $schema): void
    {
        $schema->createTable('xf_cav7_citation_template', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('template_id', 'int')->autoIncrement();
            $table->addColumn('title', 'varchar', 150);
            $table->addColumn('create_user_id', 'int');
            $table->addColumn('create_date', 'int');
        });

        $schema->createTable('xf_cav7_citation_template_version', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('template_version_id', 'int')->autoIncrement();
            $table->addColumn('template_id', 'int');
            $table->addColumn('version_number', 'smallint');
            $table->addColumn('state', 'enum')->values(['draft', 'published', 'retired'])->setDefault('draft');
            $table->addColumn('plate_hash', 'char', 64)->setDefault('');
            $table->addColumn('plate_format', 'enum')->values(['png', 'jpeg'])->nullable()->setDefault(null);
            $table->addColumn('plate_width', 'smallint')->setDefault(0);
            $table->addColumn('plate_height', 'smallint')->setDefault(0);
            $table->addColumn('rank_scope', 'enum')->values(['member', 'grant', 'none'])->setDefault('none');
            $table->addColumn('field_definitions', 'mediumblob');
            $table->addColumn('input_definitions', 'blob');
            $table->addColumn('font_hashes', 'blob');
            $table->addColumn('create_user_id', 'int');
            $table->addColumn('create_date', 'int');
            $table->addColumn('publish_user_id', 'int')->setDefault(0);
            $table->addColumn('publish_date', 'int')->setDefault(0);
            $table->addColumn('retire_date', 'int')->setDefault(0);
            $table->addUniqueKey(['template_id', 'version_number'], 'template_number');
            $table->addKey(['state', 'template_id'], 'state_template');
        });

        $schema->createTable('xf_cav7_citation_version_kind', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('template_version_id', 'int');
            $table->addColumn('kind_type', 'enum')->values(['award', 'record_type']);
            $table->addColumn('kind_id', 'int');
            $table->addPrimaryKey(['template_version_id', 'kind_type', 'kind_id']);
            $table->addKey(['kind_type', 'kind_id'], 'kind');
        });

        $schema->createTable('xf_cav7_citation_rank_art', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('template_version_id', 'int');
            $table->addColumn('field_key', 'varchar', 50);
            $table->addColumn('rank_id', 'int');
            $table->addColumn('image_hash', 'char', 64);
            $table->addColumn('image_width', 'smallint');
            $table->addColumn('image_height', 'smallint');
            $table->addPrimaryKey(['template_version_id', 'field_key', 'rank_id']);
        });

        $schema->createTable('xf_cav7_citation_signing_billet', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('billet_id', 'int')->autoIncrement();
            $table->addColumn('title', 'varchar', 100);
            $table->addColumn('display_order', 'int')->setDefault(0);
        });

        $schema->createTable('xf_cav7_citation_signature', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('signature_id', 'int')->autoIncrement();
            $table->addColumn('billet_id', 'int');
            $table->addColumn('printed_lines', 'blob');
            $table->addColumn('ink_hash', 'char', 64);
            $table->addColumn('ink_width', 'smallint');
            $table->addColumn('ink_height', 'smallint');
            $table->addColumn('state', 'enum')->values(['active', 'retired'])->setDefault('active');
            $table->addColumn('create_user_id', 'int');
            $table->addColumn('create_date', 'int');
            $table->addKey(['billet_id', 'state'], 'billet_state');
        });

        $schema->createTable('xf_cav7_citation_pay_grade', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('rank_id', 'int');
            $table->addColumn('pay_grade', 'varchar', 10)->setDefault('');
            $table->addColumn('abbreviation', 'varchar', 10)->setDefault('');
            $table->addPrimaryKey('rank_id');
        });

        $schema->createTable(TypesettingRows::TABLE, function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('grant_id', 'int');
            $table->addColumn('grant_member_id', 'int')->setDefault(0);
            $table->addColumn('field_key', 'varchar', 50);
            $table->addColumn('size', 'decimal', '6,2')->setDefault(0);
            $table->addColumn('below_legibility', 'tinyint')->setDefault(0);
            $table->addColumn('set_data', 'mediumblob');
            $table->addColumn('addon_version_id', 'int');
            $table->addColumn('imagemagick_version', 'varchar', 32);
            $table->addColumn('freetype_version', 'varchar', 32);
            $table->addColumn('typeset_date', 'int');
            $table->addPrimaryKey(['grant_id', 'grant_member_id', 'field_key']);
            $table->addKey(['below_legibility', 'grant_id'], 'below_legibility');
            $table->addKey(['addon_version_id', 'grant_id'], 'addon_version');
        });

        $schema->createTable('xf_cav7_citation_overrun_log', function (Create $table) {
            $table->checkExists(true);
            $table->addColumn('template_version_id', 'int');
            $table->addColumn('imagemagick_version', 'varchar', 32);
            $table->addColumn('freetype_version', 'varchar', 32);
            $table->addColumn('log_date', 'int');
            $table->addPrimaryKey(['template_version_id', 'imagemagick_version', 'freetype_version']);
        });
    }

    /** INSERT IGNORE, so a reinstall leaves what S1 has edited. */
    public static function seedPayGrades(AbstractAdapter $db): void
    {
        $ranks = $db->fetchPairs('SELECT rank_id, title FROM xf_nf_rosters_rank');
        foreach (PayGradeSeed::rows($ranks) as $row) {
            $db->query(
                'INSERT IGNORE INTO xf_cav7_citation_pay_grade (rank_id, pay_grade, abbreviation) VALUES (?, ?, ?)',
                array_values($row)
            );
        }
    }
}
