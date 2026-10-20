import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

const t = (key, params) => app.translator.trans(`ernestdefoe-resurface.admin.tags.${key}`, params);

/**
 * The tags members may bump in, kept as a JSON list of ids; none ticked is
 * every tag. Only shown when Tags is on. Attrs: `setting`.
 */
export default class TagChoice extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = !app.store.all('tags').length;
    if (this.loading) {
      app.store.find('tags').finally(() => {
        this.loading = false;
        m.redraw();
      });
    }
  }

  view() {
    const setting = this.attrs.setting;
    let chosen;
    try {
      chosen = (JSON.parse(setting() || '[]') || []).map(String);
    } catch (e) {
      chosen = [];
    }

    const toggle = (id, on) => {
      const next = on ? [...new Set([...chosen, id])] : chosen.filter((x) => x !== id);
      setting(JSON.stringify(next.map(Number)));
    };

    return (
      <div className="Form-group ResurfaceTagChoice">
        <label>{t('label')}</label>
        <div className="helpText">{t('help')}</div>
        {this.loading ? (
          <LoadingIndicator display="inline" />
        ) : (
          <div className="ResurfaceTagChoice-list">
            {app.store
              .all('tags')
              .sort((a, b) => (a.position() ?? 999) - (b.position() ?? 999))
              .map((tag) => (
                <label key={tag.id()} className="checkbox">
                  <input type="checkbox" checked={chosen.includes(tag.id())} onchange={(e) => toggle(tag.id(), e.target.checked)} />
                  {tag.name()}
                </label>
              ))}
          </div>
        )}
      </div>
    );
  }
}
