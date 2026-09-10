import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        { path: '', name: 'home', component: () => import('@/views/HomeView.vue') },
        { path: 'data', name: 'data', component: () => import('@/views/DataView.vue') },
        { path: 'resource', name: 'resource', component: () => import('@/views/ResourceDataView.vue') },
        { path: 'record', name: 'record', component: () => import('@/views/RecordView.vue') },
        { path: 'quiz', name: 'quiz', component: () => import('@/views/QuizView.vue') },
        { path: 'goals', name: 'goals', component: () => import('@/views/GoalsView.vue') },
        { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
        { path: 'vocabulary', name: 'vocabulary', component: () => import('@/views/VocabularyManageView.vue') },
        { path: 'review', name: 'review', component: () => import('@/views/ReviewView.vue') },
        { path: 'flashcard', name: 'flashcard', component: () => import('@/views/FlashcardView.vue') },
        { path: 'quizzes', name: 'quizzes', component: () => import('@/views/QuizzesView.vue') },
        { path: 'invoices', name: 'invoices', component: () => import('@/views/InvoicesView.vue') },
      ],
    },
    // 家庭教師用（tutor ロール）
    {
      path: '/tutor',
      component: () => import('@/layouts/TutorLayout.vue'),
      meta: { tutor: true },
      children: [
        { path: '', name: 'tutor-home', component: () => import('@/views/TutorHomeView.vue'), meta: { tutor: true } },
        {
          path: 'subjects',
          name: 'tutor-subjects',
          component: () => import('@/views/TutorSubjectsView.vue'),
          meta: { tutor: true },
        },
        {
          path: 'assignments',
          name: 'tutor-assignments',
          component: () => import('@/views/TutorAssignmentsView.vue'),
          meta: { tutor: true },
        },
        {
          path: 'quizzes',
          name: 'tutor-quizzes',
          component: () => import('@/views/TutorQuizzesView.vue'),
          meta: { tutor: true },
        },
        {
          path: 'quizzes/:id',
          name: 'tutor-quiz-grade',
          component: () => import('@/views/TutorQuizGradeView.vue'),
          meta: { tutor: true },
        },
        {
          path: 'invoices',
          name: 'tutor-invoices',
          component: () => import('@/views/TutorInvoicesView.vue'),
          meta: { tutor: true },
        },
      ],
    },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('sm_token')
  const isTutor = localStorage.getItem('sm_role') === 'tutor'

  if (!to.meta.public && !token) {
    return { name: 'login' }
  }
  if (to.name === 'login' && token) {
    return { name: isTutor ? 'tutor-home' : 'home' }
  }
  // ロールごとに入れる画面を分離（tutor は /tutor 配下のみ、owner は /tutor 以外）
  if (token && !to.meta.public) {
    if (isTutor && !to.meta.tutor) return { name: 'tutor-home' }
    if (!isTutor && to.meta.tutor) return { name: 'home' }
  }
})

export default router
