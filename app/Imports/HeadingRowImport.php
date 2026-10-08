<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Read a spreadsheet as heading-keyed rows (use with Excel::toCollection()).
 */
class HeadingRowImport implements WithHeadingRow {}
