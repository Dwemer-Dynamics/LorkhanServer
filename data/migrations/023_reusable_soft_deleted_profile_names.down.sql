DROP INDEX IF EXISTS profiles_installation_id_name_active_key;

ALTER TABLE profiles
    ADD CONSTRAINT profiles_installation_id_name_key UNIQUE (installation_id, name);
