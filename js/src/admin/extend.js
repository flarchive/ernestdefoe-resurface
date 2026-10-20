import app from 'flarum/admin/app';
import { Admin } from 'flarum/common/extenders';
import common from '../common/extend';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';
import ResurfacePage from './ResurfacePage';
import GroupLimits from './GroupLimits';
import TagChoice from './TagChoice';

const t = (key, params) => app.translator.trans(`ernestdefoe-resurface.admin.${key}`, params);

// Callbacks, not values: this module runs before the app has booted.
export default [
  ...common,
  new Admin()
    .page(ResurfacePage)
    .setting(
      () => ({
        setting: 'ernestdefoe-resurface.cooldown_hours',
        type: 'number',
        min: 0,
        step: 0.5,
        label: t('settings.cooldown_label'),
        help: t('settings.cooldown_help'),
      }),
      50
    )
    .setting(
      () => ({
        setting: 'ernestdefoe-resurface.daily_limit',
        type: 'number',
        min: 0,
        step: 1,
        label: t('settings.daily_label'),
        help: t('settings.daily_help'),
      }),
      40
    )
    .customSetting(function () {
      return (
        <GroupLimits
          setting={this.setting('ernestdefoe-resurface.group_limits', '{}')}
          defaults={{ cooldown: this.setting('ernestdefoe-resurface.cooldown_hours')(), daily: this.setting('ernestdefoe-resurface.daily_limit')() }}
        />
      );
    }, 30)
    .customSetting(function () {
      return isExtensionEnabled('flarum-tags') ? <TagChoice setting={this.setting('ernestdefoe-resurface.tags', '[]')} /> : null;
    }, 20)
    .setting(
      () => ({
        setting: 'ernestdefoe-resurface.log_days',
        type: 'number',
        min: 0,
        step: 1,
        label: t('settings.log_days_label'),
        help: t('settings.log_days_help'),
      }),
      10
    )
    .permission(() => ({ icon: 'fas fa-circle-up', label: t('permissions.bump'), permission: 'discussion.resurfaceBump' }), 'reply', 90)
    .permission(() => ({ icon: 'far fa-clock', label: t('permissions.schedule'), permission: 'discussion.resurfaceSchedule' }), 'reply', 89)
    .permission(() => ({ icon: 'fas fa-circle-up', label: t('permissions.bump_any'), permission: 'discussion.resurfaceBumpAny' }), 'moderate', 90),
];
