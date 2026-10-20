import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import TerminalPost from 'flarum/forum/components/TerminalPost';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import bump from './bump';
import ScheduleModal from './components/ScheduleModal';
import LogModal from './components/LogModal';

// Flarum puts dayjs (with relativeTime and localizedFormat) on window; humanTime
// turns future times into "just now", so the times ahead use dayjs directly.
const t = (key, params) => app.translator.trans(`ernestdefoe-resurface.forum.${key}`, params);

app.initializers.add('ernestdefoe-resurface', () => {
  // On the discussion page only: its payload is the one that says whether,
  // and when, the reader may bump it.
  extend(DiscussionControls, 'userControls', function (items, discussion, context) {
    if (!(context instanceof DiscussionPage) || !discussion.resurfaceCanBump()) return;

    const next = discussion.resurfaceNextBumpAt();
    const left = discussion.resurfaceBumpsLeft();

    items.add(
      'resurfaceBump',
      next && next > new Date() ? (
        <Button icon="fas fa-circle-up" className="disabled" title={extractText(t('wait_title'))}>
          {t('wait_button', { time: dayjs(next).fromNow() })}
        </Button>
      ) : (
        <Button
          icon="fas fa-circle-up"
          title={left == null ? undefined : extractText(t('left_title', { count: left }))}
          onclick={() => bump(discussion)}
        >
          {t('bump_button')}
        </Button>
      ),
      -10
    );

    if (discussion.resurfaceCanSchedule()) {
      const plan = discussion.resurfaceSchedule();

      items.add(
        'resurfaceSchedule',
        <Button icon="far fa-clock" onclick={() => app.modal.show(ScheduleModal, { discussion })}>
          {plan ? t('planned_button', { time: dayjs(plan.at).format('lll') }) : t('schedule_button')}
        </Button>,
        -11
      );
    }
  });

  extend(DiscussionControls, 'moderationControls', function (items, discussion) {
    if (!discussion.resurfaceCanViewLog()) return;

    items.add(
      'resurfaceLog',
      <Button icon="fas fa-clock-rotate-left" onclick={() => app.modal.show(LogModal, { discussion })}>
        {t('log_button')}
      </Button>
    );
  });

  // In the list, a bump is not a reply: say "bumped", not "<last poster> replied".
  override(TerminalPost.prototype, 'view', function (original) {
    const discussion = this.attrs.discussion;
    const bumpedAt = discussion.resurfaceBumpedAt();
    const lastPostedAt = discussion.lastPostedAt();

    if (!this.attrs.lastPost || !bumpedAt || (lastPostedAt && lastPostedAt > bumpedAt)) return original();

    return (
      <span className="ResurfaceBumped">
        <Icon name="fas fa-circle-up" /> {t('bumped_text', { ago: humanTime(bumpedAt) })}
      </span>
    );
  });
});
