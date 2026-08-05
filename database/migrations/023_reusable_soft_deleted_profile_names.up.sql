ALTER TABLE profiles DROP CONSTRAINT profiles_installation_id_name_key;

CREATE UNIQUE INDEX profiles_installation_id_name_active_key
    ON profiles (installation_id, name)
    WHERE deleted_at IS NULL;
