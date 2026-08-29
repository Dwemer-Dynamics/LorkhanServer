# Tamriel Rebuilt Oghma v5.17 review

- Catalog: `morrowind-official-3e427-v5.17`
- Final rows: **3,743**
- Tamriel Rebuilt rows: **2,443**
- New Tamriel Rebuilt subjects: **1,177**
- Existing Tamriel Rebuilt basics filled: **106**
- GLM model: `z-ai/glm-5.1`
- Fully accounted provider cost: **$6.918051891**
- Untracked provider responses: **0**

The new inventory contains 530 books, 491 ingredients, 69 artifacts, 23 creatures, 19 diseases,
15 factions, 12 cultures, 11 races, three magic subjects, two history subjects, one lore subject,
and one religion subject. The earlier v5.16 location expansion remains intact: 1,059 locations,
82 settlements, and 48 regions are present in the final catalog.

Every article has a non-empty basic description and basic class. New and backfilled basics use only
`common`; no article repeats a class between advanced and basic access. New prose passes the current
lore-only, temporal, similarity, length, class, source-scope, and record-link audits. The importer
plans 3,743 valid inserts with zero invalid or missing rows.

The 550-query deterministic retrieval comparison matched every query. The v5.17 p95 was 22.660 ms,
below the existing 50 ms gate; v5.16 measured 17.799 ms on the same machine and query set.
