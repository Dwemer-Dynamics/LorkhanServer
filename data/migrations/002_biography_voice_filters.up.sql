-- Biography voice presets use the same trusted IDs as NPC speech.
ALTER TABLE public.bio_templates ADD COLUMN tts_filter_preset text NOT NULL DEFAULT 'none';
ALTER TABLE public.bio_templates_custom ADD COLUMN tts_filter_preset text NOT NULL DEFAULT 'none';
ALTER TABLE lorkhan_internal.biography_catalog_entries ADD COLUMN tts_filter_preset text NOT NULL DEFAULT 'none';
CREATE OR REPLACE VIEW public.combined_bio_templates AS
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
    c.refid,
    c.tts_filter_preset
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
    b.refid,
    b.tts_filter_preset
   FROM (public.bio_templates b
     LEFT JOIN public.bio_templates_custom c ON (((b.npc_name)::text = (c.npc_name)::text)))
  WHERE (c.npc_name IS NULL);
