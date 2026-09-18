CREATE TABLE lorkhan_internal.npc_reference_groups (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    group_key text NOT NULL,
    name text NOT NULL,
    enabled boolean NOT NULL DEFAULT true,
    canonical_ref text NOT NULL,
    aliases jsonb NOT NULL DEFAULT '[]'::jsonb,
    PRIMARY KEY (installation_id, group_key)
);

INSERT INTO lorkhan_internal.npc_reference_groups (installation_id,group_key,name,canonical_ref,aliases)
SELECT installation_id, seed.* FROM lorkhan_internal.installations
CROSS JOIN (VALUES
 ('dagoth-ur','Dagoth Ur','morrowind.esm|258093','["morrowind.esm|262014"]'::jsonb),
 ('almalexia','Almalexia','tribunal.esm|14405','["tribunal.esm|21659"]'::jsonb),
 ('thormoor-gray-wave','Thormoor Gray-Wave','bloodmoon.esm|14571','["bloodmoon.esm|14593","bloodmoon.esm|19353"]'::jsonb)
) AS seed(group_key,name,canonical_ref,aliases);

-- New paired installations receive the same small, editable set of known alternate references.
CREATE FUNCTION lorkhan_internal.seed_npc_reference_groups() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO lorkhan_internal.npc_reference_groups(installation_id,group_key,name,canonical_ref,aliases)
  VALUES
    (NEW.installation_id,'dagoth-ur','Dagoth Ur','morrowind.esm|258093','["morrowind.esm|262014"]'::jsonb),
    (NEW.installation_id,'almalexia','Almalexia','tribunal.esm|14405','["tribunal.esm|21659"]'::jsonb),
    (NEW.installation_id,'thormoor-gray-wave','Thormoor Gray-Wave','bloodmoon.esm|14571','["bloodmoon.esm|14593","bloodmoon.esm|19353"]'::jsonb);
  RETURN NEW;
END;
$$;
CREATE TRIGGER installations_seed_reference_groups AFTER INSERT ON lorkhan_internal.installations
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.seed_npc_reference_groups();
