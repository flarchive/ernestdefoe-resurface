import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import BumpLog from '../common/BumpLog';

/** Settings, permissions, then the bump log. */
export default class ResurfacePage extends ExtensionPage {
  sections(vnode) {
    const items = super.sections(vnode);

    items.add(
      'resurfaceLog',
      <div className="ExtensionPage-settings ResurfaceAdmin-log">
        <div className="container">
          <h3 className="ExtensionPage-subHeader">{app.translator.trans('ernestdefoe-resurface.admin.log.title')}</h3>
          {this.isEnabled() && <BumpLog external link={(discussion) => `${app.forum.attribute('baseUrl')}/d/${discussion.id()}`} />}
        </div>
      </div>,
      -10
    );

    return items;
  }
}
