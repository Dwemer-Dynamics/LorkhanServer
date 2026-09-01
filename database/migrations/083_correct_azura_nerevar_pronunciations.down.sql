UPDATE lorkhan_internal.core_tts_pronunciation
SET spoken_text = CASE lower(source_text)
        WHEN 'azura' THEN 'Ah-zur-ah'
        WHEN 'nerevar' THEN 'Neh-reh-var'
    END,
    updated_at = CURRENT_TIMESTAMP
WHERE is_builtin = true
  AND lower(source_text) IN ('azura','nerevar');
