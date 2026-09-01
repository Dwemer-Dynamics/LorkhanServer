UPDATE lorkhan_internal.core_tts_pronunciation
SET spoken_text = CASE lower(source_text)
        WHEN 'azura' THEN 'ah-zu-ra'
        WHEN 'nerevar' THEN 'neh-ra-var'
    END,
    updated_at = CURRENT_TIMESTAMP
WHERE is_builtin = true
  AND lower(source_text) IN ('azura','nerevar');
