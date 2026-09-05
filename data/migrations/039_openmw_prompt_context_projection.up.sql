-- Use typed OpenMW cell identity for future and already-projected Herika locations.
DO $fix_location_projection$
DECLARE definition text;
BEGIN
    SELECT pg_get_functiondef('lorkhan_internal.project_turn_world(uuid)'::regprocedure) INTO definition;
    IF position('source_row.context#>>''{world,cell_identity,kind}''=''exterior''' IN definition) > 0 THEN RETURN; END IF;
    IF position('CASE WHEN record_key LIKE ''exterior:%'' THEN 0 ELSE 1 END' IN definition) = 0 THEN
        RAISE EXCEPTION 'Unexpected project_turn_world location classification; refusing an unsafe rewrite.';
    END IF;
    definition := replace(definition,
        'CASE WHEN record_key LIKE ''exterior:%'' THEN 0 ELSE 1 END',
        'CASE WHEN source_row.context#>>''{world,cell_identity,kind}''=''exterior'' THEN 0 ELSE 1 END');
    EXECUTE definition;
END
$fix_location_projection$;

UPDATE public.locations location
SET is_interior=CASE WHEN turn.context#>>'{world,cell_identity,kind}'='exterior' THEN 0 ELSE 1 END,
    updated_at=GREATEST(location.updated_at,turn.accepted_at AT TIME ZONE 'UTC')
FROM lorkhan_internal.location_metadata metadata
JOIN lorkhan_internal.turns turn ON turn.turn_id=metadata.source_turn_id
WHERE metadata.formid=location.formid
  AND turn.context#>>'{world,cell_identity,kind}' IN ('interior','exterior');
