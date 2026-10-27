-- CareerConnect production placement migration preflight.
-- READ-ONLY: this file contains SELECT statements only.
-- Run with an approved SELECT-only MySQL account after confirming DATABASE().
-- Do not run the migration-record query until the migrations table check below
-- reports PRESENT. Compare the returned metadata with the expected schema in
-- docs/DEPLOYMENT.md or the migration definitions before considering any
-- separate data inspection.

-- 1. Confirm the selected schema and server version.
SELECT DATABASE() AS selected_schema, VERSION() AS mysql_version;

-- 2. Confirm the Laravel migration ledger exists.
SELECT 'migrations' AS table_name,
       CASE WHEN COUNT(*) = 1 THEN 'PRESENT' ELSE 'MISSING' END AS status
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'migrations'
  AND table_type = 'BASE TABLE';

-- Run this query only if the preceding check reports PRESENT.
SELECT migration, batch
FROM migrations
WHERE migration IN (
    '2024_01_21_000001_add_placement_status_and_opportunity_permissions',
    '2024_01_21_000002_add_student_placement_details_and_history',
    '2026_10_27_000001_add_sort_order_to_opportunity_required_skills'
)
ORDER BY migration;

-- 3. Confirm all tables touched or created by the migrations exist.
SELECT expected.table_name,
       CASE WHEN actual.table_name IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS status
FROM (
    SELECT 'students' AS table_name
    UNION ALL SELECT 'opportunity_placement_policies'
    UNION ALL SELECT 'student_opportunity_placement_exceptions'
    UNION ALL SELECT 'student_placement_status_history'
    UNION ALL SELECT 'opportunity_required_skills'
) AS expected
LEFT JOIN information_schema.tables AS actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = expected.table_name
 AND actual.table_type = 'BASE TABLE'
ORDER BY expected.table_name;

-- 4. Inspect the placement columns, the full newly-created table columns,
--    and the skill-order column. Missing expected columns will not appear.
SELECT table_name, column_name, column_type, is_nullable, column_default,
       ordinal_position
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
      (table_name = 'students' AND column_name IN (
          'placement_status',
          'placement_company',
          'placement_job_role',
          'placement_package',
          'placement_date',
          'placement_status_updated_by',
          'placement_status_updated_at'
      ))
      OR table_name IN (
          'opportunity_placement_policies',
          'student_opportunity_placement_exceptions',
          'student_placement_status_history'
      )
      OR (table_name = 'opportunity_required_skills' AND column_name = 'sort_order')
  )
ORDER BY table_name, ordinal_position;

-- 5. Inspect the explicit indexes from these migrations and the base
--    opportunity_required_skills table. Compare names, uniqueness, and order.
SELECT table_name, index_name, non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS indexed_columns
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND index_name IN (
      'students_placement_status_index',
      'opp_place_policy_morph_idx',
      'opp_placement_policy_unique',
      'student_opp_exception_morph_idx',
      'student_opp_exception_active_idx',
      'student_placement_status_history_student_id_changed_at_index',
      'opportunity_required_skills_opportunity_idx',
      'opportunity_required_skills_unique'
  )
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;

-- 6. Inspect foreign keys and delete rules for the affected tables.
SELECT k.table_name, k.constraint_name, k.column_name,
       k.referenced_table_name, k.referenced_column_name, r.delete_rule
FROM information_schema.key_column_usage AS k
LEFT JOIN information_schema.referential_constraints AS r
  ON r.constraint_schema = k.constraint_schema
 AND r.table_name = k.table_name
 AND r.constraint_name = k.constraint_name
WHERE k.table_schema = DATABASE()
  AND k.referenced_table_name IS NOT NULL
  AND k.table_name IN (
      'students',
      'opportunity_placement_policies',
      'student_opportunity_placement_exceptions',
      'student_placement_status_history'
  )
ORDER BY k.table_name, k.constraint_name, k.ordinal_position;
