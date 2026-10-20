import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';

const t = (key, params) => app.translator.trans(`ernestdefoe-resurface.lib.log.${key}`, params);

/**
 * The bump log, newest first, 20 at a time. Attrs: `discussion` (only that
 * discussion's bumps, and no title column), `link(discussion)` for the
 * title's link, `external` when that link leaves the app (the admin), and
 * `onnavigate` to run when it is followed.
 */
export default class BumpLog extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.rows = [];
    this.next = false;
    this.loading = true;
    this.load(0);
  }

  load(offset) {
    this.loading = true;
    const params = { page: { offset, limit: 20 } };
    if (this.attrs.discussion) params.filter = { discussion: this.attrs.discussion.id() };

    app.store
      .find('resurface-bumps', params)
      .then((results) => {
        this.rows.push(...results);
        this.next = !!results.payload?.links?.next;
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    const one = !!this.attrs.discussion;

    if (!this.loading && !this.rows.length) {
      return <p className="ResurfaceLog-empty">{t('empty')}</p>;
    }

    return (
      <div className="ResurfaceLog">
        <table className="ResurfaceLog-table">
          <thead>
            <tr>
              <th>{t('when')}</th>
              {!one && <th>{t('discussion')}</th>}
              <th>{t('by')}</th>
              <th>{t('how')}</th>
            </tr>
          </thead>
          <tbody>
            {this.rows.map((bump) => {
              const discussion = bump.discussion();
              const user = bump.user();

              return (
                <tr key={bump.id()}>
                  <td>{humanTime(bump.createdAt())}</td>
                  {!one && (
                    <td>
                      {discussion ? (
                        <Link href={this.attrs.link(discussion)} external={this.attrs.external} onclick={this.attrs.onnavigate}>
                          {discussion.title()}
                        </Link>
                      ) : (
                        t('deleted')
                      )}
                    </td>
                  )}
                  <td className="ResurfaceLog-user">
                    {user && <Avatar user={user} />} {username(user)}
                  </td>
                  <td>{t(`source.${bump.source()}`)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {this.loading ? (
          <LoadingIndicator display="block" />
        ) : (
          this.next && (
            <Button className="Button Button--block" onclick={() => this.load(this.rows.length)}>
              {t('more')}
            </Button>
          )
        )}
      </div>
    );
  }
}
