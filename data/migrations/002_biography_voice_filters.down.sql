DO $$ BEGIN
IF EXISTS (SELECT 1 FROM public.bio_templates WHERE tts_filter_preset<>'none')
 OR EXISTS (SELECT 1 FROM public.bio_templates_custom WHERE tts_filter_preset<>'none')
 OR EXISTS (SELECT 1 FROM lorkhan_internal.biography_catalog_entries WHERE tts_filter_preset<>'none')
THEN RAISE EXCEPTION 'Cannot remove biography filters while presets are in use'; END IF;
END $$;
DROP VIEW public.combined_bio_templates;
ALTER TABLE public.bio_templates DROP COLUMN tts_filter_preset;
ALTER TABLE public.bio_templates_custom DROP COLUMN tts_filter_preset;
ALTER TABLE lorkhan_internal.biography_catalog_entries DROP COLUMN tts_filter_preset;
CREATE VIEW public.combined_bio_templates AS
 SELECT c.npc_name,
    c.oghma_knowledge_tags,
    c.core,
    c.npc_static_bio,
    c.appearance,
    c.personality,
    c.relationships,
    c.occupation,
    c.skills,
    c.speechstyle,
    c.goals,
    c.voiceid,
    c.gender,
    c.race,
    c.refid
   FROM public.bio_templates_custom c
UNION ALL
 SELECT b.npc_name,
    b.oghma_knowledge_tags,
    b.core,
    b.npc_static_bio,
    b.appearance,
    b.personality,
    b.relationships,
    b.occupation,
    b.skills,
    b.speechstyle,
    b.goals,
    b.voiceid,
    b.gender,
    b.race,
    b.refid
   FROM (public.bio_templates b
     LEFT JOIN public.bio_templates_custom c ON (((b.npc_name)::text = (c.npc_name)::text)))
  WHERE (c.npc_name IS NULL);
