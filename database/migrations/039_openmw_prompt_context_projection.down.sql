DO $restore_location_projection$
DECLARE definition text;
BEGIN
    SELECT pg_get_functiondef('almsivi_internal.project_turn_world(uuid)'::regprocedure) INTO definition;
    definition := replace(definition,
        'CASE WHEN source_row.context#>>''{world,cell_identity,kind}''=''exterior'' THEN 0 ELSE 1 END',
        'CASE WHEN record_key LIKE ''exterior:%'' THEN 0 ELSE 1 END');
    EXECUTE definition;
END
$restore_location_projection$;

UPDATE public.locations SET is_interior=CASE WHEN name LIKE 'exterior:%' THEN 0 ELSE 1 END
WHERE tags='openmw' AND world='Morrowind/OpenMW';
