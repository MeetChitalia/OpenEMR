# Public Publishing Checklist

Review this checklist before pushing the repo to GitHub as a public repository.

## Remove or Verify

- No patient data is included
- No API keys, passwords, tokens, or secrets are included
- No production URLs or private endpoints are exposed
- No client-proprietary documents or private business data are included
- No database dumps are included
- No `.env` files or private configs are included
- No real recipient email lists remain in copied code samples
- No local OS files such as `.DS_Store` are present

## Confirm Legal / Permission

- You are allowed to publish these files
- Client/company approval is not required, or has already been granted
- Open source license obligations are respected

## Improve Portfolio Value

- Add screenshots to `screenshots/`
- Add a short project summary
- Add your role and contributions clearly in `README.md`
- Keep only representative custom files if you want a cleaner showcase
- Consider adding a pinned GitHub description such as:
  `Public portfolio of OpenEMR POS, reporting, inventory, and workflow customizations.`

## Recommended Publish Steps

- Copy `openemr-custom-portfolio/` into its own folder outside the main OpenEMR repository
- Initialize a new git repository in that folder
- Push it to a new public GitHub repository
- Add 3-5 screenshots and a short repo description
- Pin the repository on your GitHub profile
