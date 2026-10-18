-- New business settings (Phase 5): the minimum pieces per size and the sample lead times.
-- JSON_INSERT only adds keys that are missing, so values staff already saved stay as they are.
UPDATE settings
SET value = JSON_INSERT(value, '$.min_pcs_per_size', 20, '$.sample_lead_time_days', 7, '$.custom_sample_lead_time_days', 14)
WHERE name = 'commerce';
