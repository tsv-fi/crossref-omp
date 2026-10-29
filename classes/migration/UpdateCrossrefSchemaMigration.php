<?php

/**
 * @file plugins/generic/crossref/classes/migration/UpdateCrossrefSchemaMigration.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class UpdateCrossrefSchemaMigration
 *
 * @brief Update the Crossref schema of the book export filter group in filter_groups.
 *  Filter groups are installed only once, so existing installations keep the old schema otherwise.
 */

namespace APP\plugins\generic\crossref\classes\migration;

use APP\plugins\generic\crossref\CrossrefExportDeployment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class UpdateCrossrefSchemaMigration extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        DB::table('filter_groups')
            ->where('symbolic', 'book=>crossref-xml')
            ->update(['output_type' => 'xml::schema(' . CrossrefExportDeployment::CROSSREF_XSI_SCHEMALOCATION . ')']);
    }

    /**
     * Reverse the downgrades
     *
     * @throws DowngradeNotSupportedException
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
