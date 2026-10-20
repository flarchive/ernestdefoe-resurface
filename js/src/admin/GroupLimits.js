import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Group from 'flarum/common/models/Group';
import Icon from 'flarum/common/components/Icon';

const t = (key, params) => app.translator.trans(`ernestdefoe-resurface.admin.groups.${key}`, params);

const parse = (json) => {
  try {
    const value = JSON.parse(json || '{}');
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  } catch (e) {
    return {};
  }
};

/**
 * Per-group overrides of the cooldown and the daily limit, kept in one JSON
 * setting. A blank box keeps the default; a group with both blank has no row.
 * Attrs: `setting` (the page's settings stream), `defaults` ({cooldown, daily})
 * shown in the blank boxes.
 */
export default class GroupLimits extends Component {
  view() {
    const setting = this.attrs.setting;
    const value = parse(setting());
    const groups = app.store.all('groups').filter((group) => ![Group.GUEST_ID, Group.ADMINISTRATOR_ID].includes(group.id()));

    const change = (id, key, input) => {
      const next = { ...value, [id]: { ...(value[id] || {}) } };
      const number = input === '' ? null : Number(input);
      next[id][key] = number === null || Number.isNaN(number) || number < 0 ? null : number;

      if (next[id].cooldown == null && next[id].daily == null) delete next[id];

      setting(JSON.stringify(next));
    };

    return (
      <div className="Form-group ResurfaceGroupLimits">
        <label>{t('label')}</label>
        <div className="helpText">{t('help')}</div>
        <table>
          <thead>
            <tr>
              <th>{t('group')}</th>
              <th>{t('cooldown')}</th>
              <th>{t('daily')}</th>
            </tr>
          </thead>
          <tbody>
            {groups.map((group) => {
              const row = value[group.id()] || {};

              return (
                <tr key={group.id()}>
                  <th scope="row">
                    {group.icon() && <Icon name={group.icon()} />} {group.namePlural()}
                  </th>
                  <td>
                    <input
                      className="FormControl"
                      type="number"
                      min="0"
                      step="0.5"
                      placeholder={this.attrs.defaults.cooldown}
                      aria-label={`${group.namePlural()}: ${t('cooldown')}`}
                      value={row.cooldown ?? ''}
                      onchange={(e) => change(group.id(), 'cooldown', e.target.value)}
                    />
                  </td>
                  <td>
                    <input
                      className="FormControl"
                      type="number"
                      min="0"
                      step="1"
                      placeholder={this.attrs.defaults.daily}
                      aria-label={`${group.namePlural()}: ${t('daily')}`}
                      value={row.daily ?? ''}
                      onchange={(e) => change(group.id(), 'daily', e.target.value)}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    );
  }
}
