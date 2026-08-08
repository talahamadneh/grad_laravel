# Task: Fix Company Image Display Issue

## Steps

- [x] Understand the task and analyze the image display issue (relative path returned instead of full URL)
- [x] Create plan and get user confirmation
- [x] Add URL accessors (`logo`, `cover_image`) to the `Company` model
- [x] Fix logo deletion logic in `CompanyController@update` to use raw DB value
- [x] Verify the `/company/profile` endpoint returns full URL for logo/cover (accessor in place, storage link confirmed)

