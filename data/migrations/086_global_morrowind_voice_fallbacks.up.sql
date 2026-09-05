-- Seed the existing Herika global table without replacing saved custom voices.
INSERT INTO public.core_tts_fallback(race,gender,voiceid)
VALUES
    ('argonian', 'male', 'mw_argonian_male'),
    ('argonian', 'female', 'mw_argonian_female'),
    ('breton', 'male', 'mw_breton_male'),
    ('breton', 'female', 'mw_breton_female'),
    ('dark_elf', 'male', 'mw_dark_elf_male'),
    ('dark_elf', 'female', 'mw_dark_elf_female'),
    ('high_elf', 'male', 'mw_high_elf_male'),
    ('high_elf', 'female', 'mw_high_elf_female'),
    ('imperial', 'male', 'mw_imperial_male'),
    ('imperial', 'female', 'mw_imperial_female'),
    ('khajiit', 'male', 'mw_khajiit_male'),
    ('khajiit', 'female', 'mw_khajiit_female'),
    ('nord', 'male', 'mw_nord_male'),
    ('nord', 'female', 'mw_nord_female'),
    ('orc', 'male', 'mw_orc_male'),
    ('orc', 'female', 'mw_orc_female'),
    ('redguard', 'male', 'mw_redguard_male'),
    ('redguard', 'female', 'mw_redguard_female'),
    ('wood_elf', 'male', 'mw_wood_elf_male'),
    ('wood_elf', 'female', 'mw_wood_elf_female')
ON CONFLICT (race,gender) DO NOTHING;
