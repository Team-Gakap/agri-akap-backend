<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Walk-in enlistment does not collect full RSBSA identity fields.
 * Sex, birthdate, name, mobile, barangay, and city stay required.
 * House/street were already nullable (2026_07_11_000003); this statement is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE farmers MODIFY permanent_house_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE farmers MODIFY permanent_street VARCHAR(255) NULL');
        DB::statement('ALTER TABLE farmers MODIFY permanent_province VARCHAR(255) NULL');
        DB::statement('ALTER TABLE farmers MODIFY permanent_region VARCHAR(255) NULL');
        DB::statement('ALTER TABLE farmers MODIFY mothers_maiden_first_name VARCHAR(255) NULL');
        DB::statement('ALTER TABLE farmers MODIFY mothers_maiden_surname VARCHAR(255) NULL');
        DB::statement("ALTER TABLE farmers MODIFY civil_status ENUM('Single','Married','Widow/er','Legally Separated') NULL");
        DB::statement("ALTER TABLE farmers MODIFY highest_education ENUM('Pre-school','Elementary','High School non K-12','Junior High School K-12','Senior High School K-12','College','Vocational','Post-graduate','None') NULL");
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::table('farmers')->whereNull('permanent_province')->update(['permanent_province' => '']);
        DB::table('farmers')->whereNull('permanent_region')->update(['permanent_region' => '']);
        DB::table('farmers')->whereNull('mothers_maiden_first_name')->update(['mothers_maiden_first_name' => '']);
        DB::table('farmers')->whereNull('mothers_maiden_surname')->update(['mothers_maiden_surname' => '']);
        DB::table('farmers')->whereNull('civil_status')->update(['civil_status' => 'Single']);
        DB::table('farmers')->whereNull('highest_education')->update(['highest_education' => 'None']);

        DB::statement('ALTER TABLE farmers MODIFY permanent_province VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE farmers MODIFY permanent_region VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE farmers MODIFY mothers_maiden_first_name VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE farmers MODIFY mothers_maiden_surname VARCHAR(255) NOT NULL');
        DB::statement("ALTER TABLE farmers MODIFY civil_status ENUM('Single','Married','Widow/er','Legally Separated') NOT NULL");
        DB::statement("ALTER TABLE farmers MODIFY highest_education ENUM('Pre-school','Elementary','High School non K-12','Junior High School K-12','Senior High School K-12','College','Vocational','Post-graduate','None') NOT NULL");
    }
};
