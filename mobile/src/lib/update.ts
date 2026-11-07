/** Pure rules for the in-app update check (kept free of React Native so they can be unit-tested). */

/** Is the release offered on the website newer than the installed build (Android versionCode)? */
export function isNewer(offered: { build_number: number } | null | undefined, installed: number): boolean {
  return Boolean(offered && installed > 0 && Number.isFinite(offered.build_number) && offered.build_number > installed);
}

/** Should the optional-update card be shown, given the build the user last dismissed? */
export function shouldShowCard(offered: { build_number: number; is_required: boolean } | null | undefined, dismissedBuild: number): boolean {
  if (!offered) return false;
  return offered.is_required || dismissedBuild < offered.build_number;
}
