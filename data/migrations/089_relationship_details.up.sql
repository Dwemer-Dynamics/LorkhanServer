-- Editable relationship context is separate from player-only Custom Info and immutable audit reasons.
ALTER TABLE lorkhan_internal.relationship_records ADD COLUMN details jsonb NOT NULL DEFAULT '{}'::jsonb
    CHECK (jsonb_typeof(details)='object'
        AND details - ARRAY['relation','note','best','worst']::text[] = '{}'::jsonb
        AND (NOT details ? 'relation' OR (jsonb_typeof(details->'relation')='string' AND char_length(details->>'relation')<=1024))
        AND (NOT details ? 'note' OR (jsonb_typeof(details->'note')='string' AND char_length(details->>'note')<=1024))
        AND (NOT details ? 'best' OR (jsonb_typeof(details->'best')='string' AND char_length(details->>'best')<=1024))
        AND (NOT details ? 'worst' OR (jsonb_typeof(details->'worst')='string' AND char_length(details->>'worst')<=1024)));
