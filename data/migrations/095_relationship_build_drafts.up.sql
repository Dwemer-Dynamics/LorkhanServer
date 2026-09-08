-- A preview receipt never mutates saved relationships; the editor applies reviewed rows later.
ALTER TABLE lorkhan_internal.relationship_build_results ADD COLUMN draft jsonb
    CHECK (draft IS NULL OR (jsonb_typeof(draft) = 'array' AND jsonb_array_length(draft) <= 20));
