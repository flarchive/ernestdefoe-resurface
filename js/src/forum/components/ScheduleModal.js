import app from 'flarum/forum/app';
import FormModal from 'flarum/common/components/FormModal';
import Form from 'flarum/common/components/Form';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import Stream from 'flarum/common/utils/Stream';

const t = (key, params) => app.translator.trans(`ernestdefoe-resurface.forum.schedule.${key}`, params);

const pad = (n) => String(n).padStart(2, '0');

/** A Date as a datetime-local value, in the browser's time zone. */
const local = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;

/**
 * Plan bumps: one at a set time, or one every so many hours or days from then,
 * a number of times or until cancelled.
 */
export default class ScheduleModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);

    const discussion = this.attrs.discussion;
    const plan = discussion.resurfaceSchedule();
    const every = plan?.every ?? Math.max(24, Math.ceil(discussion.resurfaceCooldown() || 0));
    const days = every % 24 === 0;

    this.plan = plan;
    // A plan's time can already be past (its run is due, or the scheduler was
    // down): offer the next hour instead, which the server accepts.
    const soon = Date.now() + 3600 * 1000;
    this.at = Stream(local(new Date(plan && new Date(plan.at).getTime() > Date.now() ? plan.at : soon)));
    this.repeat = Stream(!!plan?.every);
    this.amount = Stream(String(days ? every / 24 : every));
    this.unit = Stream(days ? 'days' : 'hours');
    this.times = Stream(plan?.times ? String(plan.times) : '');
  }

  className() {
    return 'ResurfaceScheduleModal Modal--small';
  }

  title() {
    return t('title');
  }

  content() {
    const cooldown = Math.ceil(this.attrs.discussion.resurfaceCooldown() || 0);

    return (
      <div className="Modal-body">
        <Form className="Form--centered">
          <div className="Form-group">
            <label for="resurface-at">{t('at_label')}</label>
            <input id="resurface-at" className="FormControl" type="datetime-local" bidi={this.at} required />
          </div>
          <div className="Form-group">
            <Switch state={this.repeat()} onchange={this.repeat}>
              {t('repeat_label')}
            </Switch>
          </div>
          {this.repeat() && (
            <div className="Form-group ResurfaceScheduleModal-every">
              <label for="resurface-every">{t('every_label')}</label>
              <div className="ResurfaceScheduleModal-row">
                <input id="resurface-every" className="FormControl" type="number" min="1" step="1" bidi={this.amount} required />
                <Select
                  value={this.unit()}
                  onchange={this.unit}
                  options={{ hours: t('hours', { count: Number(this.amount()) || 0 }), days: t('days', { count: Number(this.amount()) || 0 }) }}
                />
              </div>
              {cooldown > 0 && <p className="helpText">{t('every_help', { hours: cooldown })}</p>}
            </div>
          )}
          {this.repeat() && (
            <div className="Form-group">
              <label for="resurface-times">{t('times_label')}</label>
              <input
                id="resurface-times"
                className="FormControl"
                type="number"
                min="1"
                max="999"
                step="1"
                placeholder={t('times_placeholder')}
                bidi={this.times}
              />
            </div>
          )}
          {cooldown > 0 && <p className="helpText">{t('limits_help')}</p>}
          <div className="Form-group Form-controls">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {t('save_button')}
            </Button>
            {this.plan && (
              <Button className="Button Button--block Button--danger" type="button" disabled={this.loading} onclick={() => this.send(null)}>
                {t('cancel_button')}
              </Button>
            )}
          </div>
        </Form>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();

    const every = this.repeat() ? parseInt(this.amount(), 10) * (this.unit() === 'days' ? 24 : 1) : null;
    const times = this.repeat() && this.times() ? parseInt(this.times(), 10) : null;

    this.send({ at: new Date(this.at()).toISOString(), every, times });
  }

  send(plan) {
    this.loading = true;

    this.attrs.discussion
      .save({ resurfaceSchedule: plan })
      .then(() => {
        this.hide();
        app.alerts.show({ type: 'success' }, t(plan ? 'saved_alert' : 'cancelled_alert'));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
