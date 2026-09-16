DELETE FROM lorkhan_internal.director_plans WHERE plan_id IN (SELECT plan_id FROM lorkhan_internal.director_instructions WHERE ordinal>3);
ALTER TABLE lorkhan_internal.director_instructions DROP COLUMN authored_response;
ALTER TABLE lorkhan_internal.director_instructions DROP CONSTRAINT director_instructions_ordinal_check;
ALTER TABLE lorkhan_internal.director_instructions ADD CONSTRAINT director_instructions_ordinal_check CHECK(ordinal BETWEEN 1 AND 3);
