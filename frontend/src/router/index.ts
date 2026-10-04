import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '@/views/HomeView.vue'
import FormView from '@/views/FormView.vue'
import SagaView from '@/views/SagaView.vue'
import OrphansView from '@/views/OrphansView.vue'
import IntrudersView from '@/views/IntrudersView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    {
      path: '/add',
      name: 'form',
      component: FormView,
    },
    {
      path: '/edit/:id',
      name: 'edit',
      component: FormView,
      props: true,
    },
    {
      path: '/saga/:id',
      name: 'saga',
      component: SagaView,
    },
    {
      path: '/orphans',
      name: 'orphans',
      component: OrphansView,
    },
    {
      path: '/intruders',
      name: 'intruders',
      component: IntrudersView,
    },
  ],
})

export default router
