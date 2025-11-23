<template>
  <div class="div">
    <p class="p">
      <a href="/" class="a">首页</a>
      <span class="span"></span>
      <template v-if="category1">
        <a :href="getCategoryUrl(1)" class="a">{{ category1.name }}</a>
        <span class="span"></span>
      </template>
      <template v-if="category2">
        <a :href="getCategoryUrl(2)" class="a">{{ category2.name }}</a>
        <span class="span"></span>
      </template>
      <template v-if="category3">
        <a :href="getCategoryUrl(3)" class="a">{{ category3.name }}</a>
      </template>
    </p>
  </div>
</template>

<script setup>
const props = defineProps({
  // 一级分类
  category1: {
    type: Object,
    default: null
  },
  // 二级分类
  category2: {
    type: Object,
    default: null
  },
  // 三级分类
  category3: {
    type: Object,
    default: null
  }
})

// 生成分类链接
// 根据分类级别返回对应的链接
const getCategoryUrl = (level) => {
  if (level === 1 && props.category1) {
    return `/all-categories-products?cat1=${props.category1.id}`
  }
  if (level === 2 && props.category2) {
    return `/all-categories-products?cat1=${props.category1.id}&cat2=${props.category2.id}`
  }
  if (level === 3 && props.category3) {
    return `/all-categories-products?cat1=${props.category1.id}&cat2=${props.category2.id}&cat3=${props.category3.id}`
  }
  return '#'
}
</script>

<style scoped>
.div { line-height: 30px; background-color: rgb(242, 243, 247); }
.p { border-color: rgb(153, 153, 153); color: rgb(153, 153, 153); display: inline-block; line-height: 30px; }
.a { border-color: rgb(153, 153, 153); color: rgb(153, 153, 153); cursor: pointer; display: inline; line-height: 30px; padding-left: 5px; padding-right: 5px; transition-duration: 0.3s; }
.span { background-image: url('https://www.saleyee.com/static/imgs/2e7b600d948a440c7901a240f5eec026.png'); background-position: -64px -14px; background-repeat: no-repeat; display: inline-block; height: 16px; left: 4px; line-height: 30px; margin-right: 5px; margin-top: 3px; position: relative; top: 1px; width: 16px; }
</style>