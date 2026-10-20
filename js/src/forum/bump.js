import app from 'flarum/forum/app';

/**
 * Bumps the discussion, then lifts it to the top of the list behind the page
 * when that list orders by newest activity (the default, which leaves no sort
 * in the URL, or "Latest") and is either the unfiltered list or already holds
 * it: a tag's or a filter's list never gains a discussion it does not hold.
 */
export default function bump(discussion) {
  return discussion.save({ resurfaceBump: true }).then(() => {
    const list = app.discussions;
    const sort = list?.currentSort();
    const listed = list?.getPages().some((page) => page.items.some((item) => item.id() === discussion.id()));
    const everything = list && !list.params.tags && !Object.keys(list.params.filter || {}).length;

    if ((listed || everything) && !list.params.q && (!sort || sort === '-lastPostedAt')) {
      list.addDiscussion(discussion);
    }

    app.alerts.show({ type: 'success' }, app.translator.trans('ernestdefoe-resurface.forum.bumped_alert'));
  });
}
