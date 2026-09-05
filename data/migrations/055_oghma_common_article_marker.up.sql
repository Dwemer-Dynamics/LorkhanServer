ALTER TABLE lorkhan_internal.oghma_installation_settings
    ALTER COLUMN knowledge_tags SET DEFAULT '';

UPDATE lorkhan_internal.oghma_installation_settings settings
SET knowledge_tags = cleaned.knowledge_tags,
    updated_at = clock_timestamp()
FROM (
    SELECT installation_id,
        COALESCE(string_agg(trim(part), ', ' ORDER BY ordinal)
            FILTER (WHERE trim(part) <> '' AND lower(trim(part)) NOT IN ('common', 'esoteric')), '') AS knowledge_tags
    FROM lorkhan_internal.oghma_installation_settings source
    CROSS JOIN LATERAL regexp_split_to_table(source.knowledge_tags, E'\\s*[,|;]\\s*')
        WITH ORDINALITY AS split(part, ordinal)
    GROUP BY installation_id
) cleaned
WHERE settings.installation_id = cleaned.installation_id
  AND settings.knowledge_tags IS DISTINCT FROM cleaned.knowledge_tags;

DO $common_marker$
DECLARE
    source record;
    cleaned text;
    revised jsonb;
    next_revision integer;
BEGIN
    FOR source IN
        SELECT profile.profile_id, profile.current_revision, revision.content
        FROM lorkhan_internal.profiles profile
        JOIN lorkhan_internal.profile_revisions revision
          ON revision.profile_id = profile.profile_id
         AND revision.revision = profile.current_revision
        WHERE revision.content ? 'oghma_knowledge_tags'
    LOOP
        SELECT COALESCE(string_agg(trim(part), ', ' ORDER BY ordinal)
            FILTER (WHERE trim(part) <> '' AND lower(trim(part)) NOT IN ('common', 'esoteric')), '')
        INTO cleaned
        FROM regexp_split_to_table(source.content->>'oghma_knowledge_tags', E'\\s*[,|;]\\s*')
            WITH ORDINALITY AS split(part, ordinal);
        IF cleaned IS DISTINCT FROM source.content->>'oghma_knowledge_tags' THEN
            revised := jsonb_set(source.content, '{oghma_knowledge_tags}', to_jsonb(cleaned), true);
            next_revision := source.current_revision + 1;
            INSERT INTO lorkhan_internal.profile_revisions(profile_id, revision, content, change_reason)
            VALUES(source.profile_id, next_revision, revised, '055 remove article-only Oghma markers from NPC');
            UPDATE lorkhan_internal.profiles SET current_revision = next_revision WHERE profile_id = source.profile_id;
        END IF;
    END LOOP;

    FOR source IN
        SELECT profile.core_profile_id, profile.current_revision, revision.content
        FROM lorkhan_internal.core_profiles profile
        JOIN lorkhan_internal.core_profile_revisions revision
          ON revision.core_profile_id = profile.core_profile_id
         AND revision.revision = profile.current_revision
        WHERE revision.content#>>'{settings_overrides,memory,oghma_knowledge_tags}' IS NOT NULL
    LOOP
        SELECT COALESCE(string_agg(trim(part), ', ' ORDER BY ordinal)
            FILTER (WHERE trim(part) <> '' AND lower(trim(part)) NOT IN ('common', 'esoteric')), '')
        INTO cleaned
        FROM regexp_split_to_table(source.content#>>'{settings_overrides,memory,oghma_knowledge_tags}', E'\\s*[,|;]\\s*')
            WITH ORDINALITY AS split(part, ordinal);
        IF cleaned IS DISTINCT FROM source.content#>>'{settings_overrides,memory,oghma_knowledge_tags}' THEN
            revised := jsonb_set(source.content, '{settings_overrides,memory,oghma_knowledge_tags}', to_jsonb(cleaned), true);
            next_revision := source.current_revision + 1;
            INSERT INTO lorkhan_internal.core_profile_revisions(core_profile_id, revision, content, change_reason)
            VALUES(source.core_profile_id, next_revision, revised, '055 remove article-only Oghma markers from NPC defaults');
            UPDATE lorkhan_internal.core_profiles SET current_revision = next_revision WHERE core_profile_id = source.core_profile_id;
        END IF;
    END LOOP;
END
$common_marker$;

UPDATE public.bio_templates_custom template
SET oghma_knowledge_tags = cleaned.knowledge_tags
FROM (
    SELECT npc_name,
        COALESCE(string_agg(trim(part), ', ' ORDER BY ordinal)
            FILTER (WHERE trim(part) <> '' AND lower(trim(part)) NOT IN ('common', 'esoteric')), '') AS knowledge_tags
    FROM public.bio_templates_custom source
    CROSS JOIN LATERAL regexp_split_to_table(COALESCE(source.oghma_knowledge_tags, ''), E'\\s*[,|;]\\s*')
        WITH ORDINALITY AS split(part, ordinal)
    GROUP BY npc_name
) cleaned
WHERE template.npc_name = cleaned.npc_name
  AND COALESCE(template.oghma_knowledge_tags, '') IS DISTINCT FROM cleaned.knowledge_tags;

UPDATE public.core_npc_master npc
SET oghma_knowledge_tags = cleaned.knowledge_tags
FROM (
    SELECT id,
        COALESCE(string_agg(trim(part), ', ' ORDER BY ordinal)
            FILTER (WHERE trim(part) <> '' AND lower(trim(part)) NOT IN ('common', 'esoteric')), '') AS knowledge_tags
    FROM public.core_npc_master source
    CROSS JOIN LATERAL regexp_split_to_table(COALESCE(source.oghma_knowledge_tags, ''), E'\\s*[,|;]\\s*')
        WITH ORDINALITY AS split(part, ordinal)
    GROUP BY id
) cleaned
WHERE npc.id = cleaned.id
  AND COALESCE(npc.oghma_knowledge_tags, '') IS DISTINCT FROM cleaned.knowledge_tags;
